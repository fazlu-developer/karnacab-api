import { randomBytes } from 'crypto';
import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
  UnauthorizedException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { LedgerDirection, Prisma, UserRole, WalletOwnerType } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { WalletsService } from '../wallets/wallets.service';
import { Actor } from '../access/territory';
import {
  GATEWAY_METHODS,
  InvoiceKind,
  PAYMENT_METHODS,
  canRefundPayment,
  canRetryPayment,
  gatewayForMethod,
  intentForMethod,
  invoiceStatus,
  needsGatewayWebhook,
  normalizePaymentMethod,
  refundKind,
} from './payment.model';
import { canonicalFromWebhookBody, verifyPaymentWebhookSignature } from './payment-signature';
import { CreatePaymentIntentDto, FailPaymentDto, PaymentWebhookDto, RefundPaymentDto } from './dto/payments.dto';

export type PayTargetInput = {
  method: string;
  intent?: 'capture' | 'advance' | 'partial' | 'cancel_fee';
  amountPaise?: number;
  bookingId?: bigint;
  parcelId?: bigint;
  travelBookingId?: bigint;
  bulkBookingId?: bigint;
  corporateInvoiceId?: bigint;
  note?: string;
};

@Injectable()
export class PaymentsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly wallets: WalletsService,
    private readonly config: ConfigService,
    private readonly events: DomainEvents,
  ) {}

  catalog() {
    return {
      methods: PAYMENT_METHODS.map((method) => ({
        method,
        gateway: GATEWAY_METHODS.includes(method as (typeof GATEWAY_METHODS)[number]),
        capture: method === 'wallet' ? 'ledger' : method === 'cash' ? 'confirm_cash' : 'webhook',
      })),
      kinds: ['payment', 'refund', 'partial_refund', 'cancellation_charge'],
      note: 'Capture is server-side only. Mobile success is ignored. UPI/card require a signed webhook.',
    };
  }

  async history(actor: Actor) {
    const where = actor.unrestricted
      ? {}
      : actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE
        ? { customerId: actor.userId }
        : { OR: [{ customerId: actor.userId }, { booking: { driver: { userId: actor.userId } } }] };
    const rows = await this.prisma.payment.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: { invoice: true },
    });
    return { payments: rows.map((row) => this.presentPayment(row)) };
  }

  async invoices(actor: Actor) {
    const where = actor.unrestricted ? {} : { customerId: actor.userId };
    const rows = await this.prisma.invoice.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      take: 100,
    });
    return { invoices: rows.map((row) => this.presentInvoice(row)) };
  }

  async onePayment(actor: Actor, id: bigint) {
    const row = await this.prisma.payment.findUnique({
      where: { id },
      include: { invoice: true, events: { orderBy: { createdAt: 'desc' }, take: 20 } },
    });
    if (!row) {
      throw new NotFoundException('Payment not found');
    }
    this.assertCanRead(actor, row.customerId);
    return this.presentPayment(row);
  }

  async oneInvoice(actor: Actor, id: bigint) {
    const row = await this.prisma.invoice.findUnique({
      where: { id },
      include: { payments: { orderBy: { createdAt: 'desc' } } },
    });
    if (!row) {
      throw new NotFoundException('Invoice not found');
    }
    this.assertCanRead(actor, row.customerId);
    return {
      ...this.presentInvoice(row),
      payments: row.payments.map((item) => this.presentPayment(item)),
    };
  }

  async initiate(actor: Actor, dto: CreatePaymentIntentDto | PayTargetInput) {
    const input: PayTargetInput = {
      method: dto.method,
      intent: 'intent' in dto ? dto.intent : undefined,
      amountPaise: dto.amountPaise,
      bookingId: 'bookingId' in dto && dto.bookingId != null ? BigInt(dto.bookingId) : undefined,
      parcelId: 'parcelId' in dto && dto.parcelId != null ? BigInt(dto.parcelId) : undefined,
      travelBookingId:
        'travelBookingId' in dto && dto.travelBookingId != null ? BigInt(dto.travelBookingId) : undefined,
      bulkBookingId: 'bulkBookingId' in dto && dto.bulkBookingId != null ? BigInt(dto.bulkBookingId) : undefined,
      corporateInvoiceId:
        'corporateInvoiceId' in dto && dto.corporateInvoiceId != null
          ? BigInt(dto.corporateInvoiceId)
          : undefined,
      note: 'note' in dto ? dto.note : undefined,
    };
    return this.prisma.$transaction((tx) => this.initiateInTx(tx, actor, input));
  }

  async confirmCash(actor: Actor, id: bigint) {
    return this.prisma.$transaction((tx) => this.captureInTx(tx, actor, id, 'cash_confirm'));
  }

  async retry(actor: Actor, id: bigint) {
    const row = await this.prisma.payment.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Payment not found');
    }
    this.assertCanRead(actor, row.customerId);
    if (!canRetryPayment(row.status) || row.kind !== 'payment') {
      throw new BadRequestException('This payment cannot be retried');
    }
    if (row.status === 'pending') {
      return this.onePayment(actor, id);
    }
    return this.prisma.$transaction(async (tx) => {
      await tx.payment.update({
        where: { id: row.id },
        data: { status: 'failed', failureCode: 'superseded', failureNote: 'Replaced by retry' },
      });
      return this.initiateInTx(tx, actor, {
        method: row.method,
        intent: row.intent as PayTargetInput['intent'],
        amountPaise: Number(row.amountPaise),
        bookingId: row.bookingId ?? undefined,
        parcelId: row.parcelId ?? undefined,
        travelBookingId: row.travelBookingId ?? undefined,
        bulkBookingId: row.bulkBookingId ?? undefined,
        corporateInvoiceId: row.corporateInvoiceId ?? undefined,
        note: `Retry of ${row.publicRef}`,
      });
    });
  }

  async markFailed(actor: Actor, id: bigint, dto: FailPaymentDto) {
    const row = await this.prisma.payment.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Payment not found');
    }
    this.assertCanRead(actor, row.customerId);
    if (row.status !== 'pending') {
      throw new BadRequestException('Only pending payments can fail');
    }
    const updated = await this.prisma.payment.update({
      where: { id },
      data: {
        status: 'failed',
        failureCode: dto.code ?? 'gateway_failed',
        failureNote: dto.note ?? 'Payment failed',
      },
    });
    await this.prisma.paymentEvent.create({
      data: {
        paymentId: id,
        source: 'api',
        eventType: 'payment.failed',
        payload: { code: updated.failureCode, note: updated.failureNote } as Prisma.InputJsonValue,
      },
    });
    return this.presentPayment(updated);
  }

  async refund(actor: Actor, id: bigint, dto: RefundPaymentDto) {
    if (!actor.unrestricted && actor.role !== UserRole.ADMIN && actor.role !== UserRole.SUPER_ADMIN) {
      throw new ForbiddenException('Only admins can refund');
    }
    return this.prisma.$transaction((tx) => this.refundInTx(tx, actor, id, dto));
  }

  async handleWebhook(provider: string, signature: string | undefined, body: PaymentWebhookDto) {
    const secret = this.webhookSecret();
    const canonical = canonicalFromWebhookBody(body);
    const ok = verifyPaymentWebhookSignature(secret, canonical, signature);
    if (!ok) {
      throw new UnauthorizedException('Invalid webhook signature');
    }
    const payment = await this.prisma.payment.findUnique({ where: { publicRef: body.paymentRef } });
    if (!payment) {
      throw new NotFoundException('Payment not found');
    }
    await this.prisma.paymentEvent.create({
      data: {
        paymentId: payment.id,
        source: 'webhook',
        eventType: body.event,
        gatewayEventId: body.gatewayEventId ?? `${provider}:${body.event}:${body.paymentRef}:${body.amountPaise}`,
        payload: body as unknown as Prisma.InputJsonValue,
        signatureValid: ok,
      },
    }).catch((error: { code?: string }) => {
      if (error.code !== 'P2002') {
        throw error;
      }
    });
    const expectedGateway = this.config.get<string>('payments.gateway') ?? 'demo';
    if (provider !== 'demo' && provider !== expectedGateway) {
      throw new BadRequestException('Unknown payment gateway');
    }
    if (body.event === 'payment.failed') {
      if (payment.status === 'pending') {
        await this.prisma.payment.update({
          where: { id: payment.id },
          data: { status: 'failed', failureCode: 'webhook_failed', failureNote: body.event },
        });
      }
      return this.presentPayment(await this.prisma.payment.findUniqueOrThrow({ where: { id: payment.id } }));
    }
    if (body.event !== 'payment.captured' && body.event !== 'payment.refunded') {
      throw new BadRequestException('Unsupported webhook event');
    }
    const systemActor: Actor = {
      userId: 0n,
      role: UserRole.ADMIN,
      status: 'ACTIVE',
      districtId: null,
      stateId: null,
      driverId: null,
      fleetOwnerId: null,
      unrestricted: true,
    };
    if (body.event === 'payment.refunded') {
      return this.prisma.$transaction((tx) =>
        this.refundInTx(tx, systemActor, payment.id, { amountPaise: body.amountPaise }),
      );
    }
    if (Number(payment.amountPaise) !== body.amountPaise) {
      throw new BadRequestException('Webhook amount does not match the payment');
    }
    return this.prisma.$transaction((tx) =>
      this.captureInTx(tx, systemActor, payment.id, 'webhook', {
        gatewayPaymentId: body.gatewayPaymentId,
      }),
    );
  }

  async levyCancellation(tx: Prisma.TransactionClient, booking: {
    id: bigint;
    customerId: bigint;
    quoteSnapshot?: unknown;
  }) {
    const snapshot =
      booking.quoteSnapshot && typeof booking.quoteSnapshot === 'object'
        ? (booking.quoteSnapshot as Record<string, unknown>)
        : {};
    const breakdown =
      snapshot.breakdown && typeof snapshot.breakdown === 'object'
        ? (snapshot.breakdown as Record<string, unknown>)
        : {};
    const cancelPaise = Number(breakdown.cancelPaise ?? snapshot.cancelPaise ?? 0) || 0;
    if (cancelPaise <= 0) {
      return null;
    }
    const actor: Actor = {
      userId: booking.customerId,
      role: UserRole.CUSTOMER,
      status: 'ACTIVE',
      districtId: null,
      stateId: null,
      driverId: null,
      fleetOwnerId: null,
      unrestricted: false,
    };
    return this.initiateInTx(tx, actor, {
      method: 'cash',
      intent: 'cancel_fee',
      amountPaise: cancelPaise,
      bookingId: booking.id,
      note: 'Cancellation charge',
    });
  }

  async issueRideInvoice(tx: Prisma.TransactionClient, booking: {
    id: bigint;
    customerId: bigint;
    quotePaise?: bigint | null;
    quoteSnapshot?: unknown;
  }) {
    await this.ensureInvoice(tx, {
      kind: 'ride',
      customerId: booking.customerId,
      bookingId: booking.id,
      totalPaise: Number(booking.quotePaise ?? 0),
      snapshot: booking.quoteSnapshot,
    });
  }

  presentPayment(row: {
    id: bigint;
    publicRef: string;
    method: string;
    kind: string;
    intent: string;
    amountPaise: bigint;
    status: string;
    gateway: string;
    gatewayOrderId?: string | null;
    gatewayPaymentId?: string | null;
    failureCode?: string | null;
    failureNote?: string | null;
    verifiedAt?: Date | null;
    verifiedSource?: string | null;
    attemptNo?: number;
    note?: string | null;
    bookingId?: bigint | null;
    parcelId?: bigint | null;
    travelBookingId?: bigint | null;
    bulkBookingId?: bigint | null;
    corporateInvoiceId?: bigint | null;
    invoiceId?: bigint | null;
    createdAt: Date;
    updatedAt?: Date;
    invoice?: { publicRef: string; kind: string; status: string } | null;
  }) {
    const pendingGateway = row.status === 'pending' && needsGatewayWebhook(normalizePaymentMethod(row.method));
    return {
      id: row.id.toString(),
      transactionId: row.publicRef,
      method: row.method,
      kind: row.kind,
      intent: row.intent,
      amountPaise: Number(row.amountPaise),
      amountRupees: Number(row.amountPaise) / 100,
      status: row.status,
      gateway: row.gateway,
      gatewayOrderId: row.gatewayOrderId ?? null,
      gatewayPaymentId: row.gatewayPaymentId ?? null,
      failureCode: row.failureCode ?? null,
      failureNote: row.failureNote ?? null,
      verifiedAt: row.verifiedAt?.toISOString() ?? null,
      verifiedSource: row.verifiedSource ?? null,
      attemptNo: row.attemptNo ?? 1,
      description: row.note ?? null,
      bookingId: row.bookingId?.toString() ?? null,
      parcelId: row.parcelId?.toString() ?? null,
      travelBookingId: row.travelBookingId?.toString() ?? null,
      bulkBookingId: row.bulkBookingId?.toString() ?? null,
      corporateInvoiceId: row.corporateInvoiceId?.toString() ?? null,
      invoiceId: row.invoiceId?.toString() ?? null,
      invoiceRef: row.invoice?.publicRef ?? null,
      createdAt: row.createdAt.toISOString(),
      clientCaptureIgnored: true,
      next:
        row.status === 'pending' && row.method === 'cash'
          ? { action: 'confirm_cash', path: `/payments/${row.id.toString()}/confirm-cash` }
          : pendingGateway
            ? {
                action: 'webhook',
                path: `/payments/webhooks/${row.gateway}`,
                canonical: 'event|paymentRef|amountPaise',
              }
            : null,
    };
  }

  presentInvoice(row: {
    id: bigint;
    publicRef: string;
    kind: string;
    status: string;
    currency: string;
    subtotalPaise: bigint;
    taxPaise: bigint;
    totalPaise: bigint;
    paidPaise: bigint;
    refundedPaise: bigint;
    lines: unknown;
    issuedAt: Date;
    bookingId?: bigint | null;
    parcelId?: bigint | null;
    travelBookingId?: bigint | null;
    bulkBookingId?: bigint | null;
    corporateInvoiceId?: bigint | null;
  }) {
    return {
      id: row.id.toString(),
      invoiceNumber: row.publicRef,
      kind: row.kind,
      status: row.status,
      currency: row.currency,
      subtotalPaise: Number(row.subtotalPaise),
      taxPaise: Number(row.taxPaise),
      totalPaise: Number(row.totalPaise),
      paidPaise: Number(row.paidPaise),
      refundedPaise: Number(row.refundedPaise),
      totalRupees: Number(row.totalPaise) / 100,
      paidRupees: Number(row.paidPaise) / 100,
      lines: row.lines,
      issuedAt: row.issuedAt.toISOString(),
      bookingId: row.bookingId?.toString() ?? null,
      parcelId: row.parcelId?.toString() ?? null,
      travelBookingId: row.travelBookingId?.toString() ?? null,
      bulkBookingId: row.bulkBookingId?.toString() ?? null,
      corporateInvoiceId: row.corporateInvoiceId?.toString() ?? null,
    };
  }

  private async initiateInTx(tx: Prisma.TransactionClient, actor: Actor, input: PayTargetInput) {
    let method: ReturnType<typeof normalizePaymentMethod>;
    try {
      method = normalizePaymentMethod(input.method);
    } catch {
      throw new BadRequestException('Unsupported payment method');
    }
    const intent = intentForMethod(method, input.intent);
    const chargeMethod = method === 'advance' || method === 'partial' ? 'upi' : method;
    const target = await this.loadTarget(tx, actor, input);
    const amountPaise = input.amountPaise ?? target.amountPaise;
    if (!Number.isFinite(amountPaise) || amountPaise <= 0) {
      throw new BadRequestException('Payment amount must be greater than zero');
    }
    const invoice = await this.ensureInvoice(tx, {
      kind: target.kind,
      customerId: target.customerId,
      bookingId: target.bookingId,
      parcelId: target.parcelId,
      travelBookingId: target.travelBookingId,
      bulkBookingId: target.bulkBookingId,
      corporateInvoiceId: target.corporateInvoiceId,
      totalPaise: target.invoiceTotalPaise,
      snapshot: target.snapshot,
    });
    const pending = await tx.payment.findFirst({
      where: {
        invoiceId: invoice.id,
        status: 'pending',
        intent,
        kind: { in: ['payment', 'cancellation_charge'] },
      },
    });
    if (pending) {
      return this.presentPayment({
        ...pending,
        invoice: { publicRef: invoice.publicRef, kind: invoice.kind, status: invoice.status },
      });
    }
    const gateway = gatewayForMethod(chargeMethod, this.config.get<string>('payments.gateway') ?? 'demo');
    const kind = intent === 'cancel_fee' ? 'cancellation_charge' : 'payment';
    const payment = await tx.payment.create({
      data: {
        publicRef: this.newRef('KCP'),
        customerId: target.customerId,
        bookingId: target.bookingId ?? null,
        parcelId: target.parcelId ?? null,
        travelBookingId: target.travelBookingId ?? null,
        bulkBookingId: target.bulkBookingId ?? null,
        corporateInvoiceId: target.corporateInvoiceId ?? null,
        invoiceId: invoice.id,
        method: method === 'advance' || method === 'partial' ? method : chargeMethod,
        kind,
        intent,
        amountPaise: BigInt(amountPaise),
        status: 'pending',
        gateway,
        gatewayOrderId: needsGatewayWebhook(chargeMethod) ? this.newRef('KCO') : null,
        note: input.note ?? null,
        attemptNo: 1,
      },
    });
    await tx.paymentEvent.create({
      data: {
        paymentId: payment.id,
        source: 'api',
        eventType: 'payment.initiated',
        payload: { method, intent, amountPaise } as Prisma.InputJsonValue,
      },
    });
    if (chargeMethod === 'wallet') {
      return this.captureInTx(tx, actor, payment.id, 'wallet_ledger');
    }
    if (method === 'cash') {
      await this.markEntityPending(tx, target, 'cash');
    }
    return this.presentPayment({ ...payment, invoice: { publicRef: invoice.publicRef, kind: invoice.kind, status: invoice.status } });
  }

  private async captureInTx(
    tx: Prisma.TransactionClient,
    actor: Actor,
    paymentId: bigint,
    source: 'webhook' | 'cash_confirm' | 'wallet_ledger',
    extra?: { gatewayPaymentId?: string },
  ) {
    const payment = await tx.payment.findUnique({ where: { id: paymentId } });
    if (!payment) {
      throw new NotFoundException('Payment not found');
    }
    if (payment.status === 'captured') {
      return this.presentPayment(payment);
    }
    if (payment.status !== 'pending') {
      throw new BadRequestException('Payment is not awaiting capture');
    }
    if (source === 'cash_confirm') {
      this.assertCanConfirmCash(actor, payment);
      if (payment.method !== 'cash') {
        throw new BadRequestException('Cash confirmation is only for cash payments');
      }
    }
    if (source === 'webhook' && !needsGatewayWebhook(normalizePaymentMethod(payment.method))) {
      throw new BadRequestException('This method is not captured by webhook');
    }
    if (source === 'wallet_ledger' && payment.method !== 'wallet' && payment.kind !== 'cancellation_charge') {
      throw new BadRequestException('Wallet capture is only for wallet payments');
    }
    if (source === 'wallet_ledger' && payment.customerId) {
      await this.wallets.post(
        {
          ownerType: WalletOwnerType.CUSTOMER,
          ownerUserId: payment.customerId,
          direction: LedgerDirection.DEBIT,
          amountPaise: Number(payment.amountPaise),
          commissionPaise: 0,
          grossPaise: Number(payment.amountPaise),
          bookingId: payment.bookingId,
          kind: payment.kind === 'cancellation_charge' ? 'adjustment' : 'trip',
          note: payment.note ?? `Payment ${payment.publicRef}`,
        },
        tx,
      );
    }
    const updated = await tx.payment.update({
      where: { id: payment.id },
      data: {
        status: 'captured',
        verifiedAt: new Date(),
        verifiedSource: source,
        gatewayPaymentId: extra?.gatewayPaymentId ?? payment.gatewayPaymentId,
      },
    });
    if (payment.invoiceId) {
      const invoice = await tx.invoice.findUnique({ where: { id: payment.invoiceId } });
      if (invoice) {
        const paidPaise = Number(invoice.paidPaise) + Number(payment.amountPaise);
        await tx.invoice.update({
          where: { id: invoice.id },
          data: {
            paidPaise: BigInt(paidPaise),
            status: invoiceStatus(paidPaise, Number(invoice.refundedPaise), Number(invoice.totalPaise)),
          },
        });
      }
    }
    await this.applyEntityPaid(tx, updated);
    await tx.paymentEvent.create({
      data: {
        paymentId: payment.id,
        source: source === 'webhook' ? 'webhook' : 'system',
        eventType: 'payment.captured',
        payload: { source } as Prisma.InputJsonValue,
        signatureValid: source === 'webhook',
      },
    });
    this.events.emit('payment.captured', {
      paymentId: updated.id.toString(),
      customerId: updated.customerId?.toString() ?? null,
    });
    return this.presentPayment(updated);
  }

  private async refundInTx(
    tx: Prisma.TransactionClient,
    _actor: Actor,
    id: bigint,
    dto: RefundPaymentDto,
  ) {
    const payment = await tx.payment.findUnique({ where: { id } });
    if (!payment) {
      throw new NotFoundException('Payment not found');
    }
    if (payment.kind !== 'payment' && payment.kind !== 'cancellation_charge') {
      throw new BadRequestException('Refunds apply to captured charges');
    }
    if (!canRefundPayment(payment.status)) {
      throw new BadRequestException('Payment is not refundable');
    }
    const already = await tx.payment.aggregate({
      where: { parentId: payment.id, status: 'captured', kind: { in: ['refund', 'partial_refund'] } },
      _sum: { amountPaise: true },
    });
    const remaining = Number(payment.amountPaise) - Number(already._sum.amountPaise ?? 0);
    const amount = dto.amountPaise ?? remaining;
    if (amount <= 0 || amount > remaining) {
      throw new BadRequestException('Refund amount is not valid');
    }
    const kind = refundKind(amount, Number(payment.amountPaise));
    const refund = await tx.payment.create({
      data: {
        publicRef: this.newRef('KCR'),
        customerId: payment.customerId,
        bookingId: payment.bookingId,
        parcelId: payment.parcelId,
        travelBookingId: payment.travelBookingId,
        bulkBookingId: payment.bulkBookingId,
        corporateInvoiceId: payment.corporateInvoiceId,
        invoiceId: payment.invoiceId,
        parentId: payment.id,
        method: payment.method,
        kind,
        intent: 'refund',
        amountPaise: BigInt(amount),
        status: 'captured',
        gateway: payment.gateway,
        verifiedAt: new Date(),
        verifiedSource: 'admin',
        note: dto.reason ?? `Refund of ${payment.publicRef}`,
      },
    });
    if (payment.method === 'wallet' && payment.customerId) {
      await this.wallets.post(
        {
          ownerType: WalletOwnerType.CUSTOMER,
          ownerUserId: payment.customerId,
          direction: LedgerDirection.CREDIT,
          amountPaise: amount,
          commissionPaise: 0,
          grossPaise: amount,
          bookingId: payment.bookingId,
          kind: 'reversal',
          note: refund.note ?? 'Refund',
        },
        tx,
      );
    }
    const priorRefunded = Number(already._sum.amountPaise ?? 0);
    const nextRefunded = priorRefunded + amount;
    await tx.payment.update({
      where: { id: payment.id },
      data: {
        status: nextRefunded >= Number(payment.amountPaise) ? 'refunded' : 'partially_refunded',
      },
    });
    if (payment.invoiceId) {
      const invoice = await tx.invoice.findUnique({ where: { id: payment.invoiceId } });
      if (invoice) {
        const refundedPaise = Number(invoice.refundedPaise) + amount;
        await tx.invoice.update({
          where: { id: invoice.id },
          data: {
            refundedPaise: BigInt(refundedPaise),
            status: invoiceStatus(Number(invoice.paidPaise), refundedPaise, Number(invoice.totalPaise)),
          },
        });
      }
    }
    await tx.paymentEvent.create({
      data: {
        paymentId: payment.id,
        source: 'system',
        eventType: 'payment.refunded',
        payload: { refundId: refund.publicRef, amount } as Prisma.InputJsonValue,
      },
    });
    this.events.emit('payment.refunded', {
      paymentId: refund.id.toString(),
      customerId: payment.customerId?.toString() ?? null,
      amountPaise: amount,
    });
    return this.presentPayment(refund);
  }

  private async loadTarget(tx: Prisma.TransactionClient, actor: Actor, input: PayTargetInput) {
    const count = [
      input.bookingId,
      input.parcelId,
      input.travelBookingId,
      input.bulkBookingId,
      input.corporateInvoiceId,
    ].filter(Boolean).length;
    if (count !== 1) {
      throw new BadRequestException('Payment must target exactly one invoiceable record');
    }
    if (input.bookingId) {
      const row = await tx.booking.findUnique({ where: { id: input.bookingId } });
      if (!row) {
        throw new NotFoundException('Booking not found');
      }
      this.assertOwner(actor, row.customerId);
      return {
        kind: 'ride' as InvoiceKind,
        customerId: row.customerId,
        bookingId: row.id,
        amountPaise: Number(row.quotePaise ?? 0),
        invoiceTotalPaise: Number(row.quotePaise ?? 0),
        snapshot: row.quoteSnapshot,
      };
    }
    if (input.parcelId) {
      const row = await tx.parcelShipment.findUnique({ where: { id: input.parcelId } });
      if (!row) {
        throw new NotFoundException('Parcel not found');
      }
      this.assertOwner(actor, row.customerId);
      return {
        kind: 'parcel' as InvoiceKind,
        customerId: row.customerId,
        parcelId: row.id,
        amountPaise: Number(row.quotePaise ?? 0),
        invoiceTotalPaise: Number(row.quotePaise ?? 0),
        snapshot: row.quoteSnapshot,
      };
    }
    if (input.travelBookingId) {
      const row = await tx.travelBooking.findUnique({ where: { id: input.travelBookingId } });
      if (!row) {
        throw new NotFoundException('Travel booking not found');
      }
      this.assertOwner(actor, row.customerId);
      return {
        kind: 'travel' as InvoiceKind,
        customerId: row.customerId,
        travelBookingId: row.id,
        amountPaise: Number(row.quotePaise),
        invoiceTotalPaise: Number(row.quotePaise),
        snapshot: row.quoteSnapshot,
      };
    }
    if (input.bulkBookingId) {
      const row = await tx.bulkBooking.findUnique({ where: { id: input.bulkBookingId } });
      if (!row) {
        throw new NotFoundException('Bulk booking not found');
      }
      this.assertOwner(actor, row.customerId);
      const intent = intentForMethod(normalizePaymentMethod(input.method), input.intent);
      const amount =
        intent === 'advance' ? Number(row.advancePaise ?? 0) : Number(row.invoicePaise ?? row.quotePaise ?? 0);
      return {
        kind: 'bulk' as InvoiceKind,
        customerId: row.customerId,
        bulkBookingId: row.id,
        amountPaise: amount,
        invoiceTotalPaise: Number(row.quotePaise ?? 0),
        snapshot: row.quoteSnapshot,
      };
    }
    const row = await tx.corporateInvoice.findUnique({ where: { id: input.corporateInvoiceId } });
    if (!row) {
      throw new NotFoundException('Invoice not found');
    }
    const account = await tx.corporateAccount.findUnique({ where: { id: row.accountId } });
    this.assertOwner(actor, account?.ownerUserId ?? 0n);
    return {
      kind: 'corporate' as InvoiceKind,
      customerId: account!.ownerUserId,
      corporateInvoiceId: row.id,
      amountPaise: Number(row.totalPaise),
      invoiceTotalPaise: Number(row.totalPaise),
      snapshot: {
        subtotalPaise: Number(row.subtotalPaise),
        gstPaise: Number(row.gstPaise),
        totalPaise: Number(row.totalPaise),
      },
    };
  }

  private async ensureInvoice(
    tx: Prisma.TransactionClient,
    input: {
      kind: InvoiceKind;
      customerId: bigint;
      bookingId?: bigint;
      parcelId?: bigint;
      travelBookingId?: bigint;
      bulkBookingId?: bigint;
      corporateInvoiceId?: bigint;
      totalPaise: number;
      snapshot: unknown;
    },
  ) {
    if (!input.bookingId && !input.parcelId && !input.travelBookingId && !input.bulkBookingId && !input.corporateInvoiceId) {
      throw new BadRequestException('Invoice needs a source record');
    }
    const existing = await tx.invoice.findFirst({
      where: input.bookingId
        ? { bookingId: input.bookingId }
        : input.parcelId
          ? { parcelId: input.parcelId }
          : input.travelBookingId
            ? { travelBookingId: input.travelBookingId }
            : input.bulkBookingId
              ? { bulkBookingId: input.bulkBookingId }
              : { corporateInvoiceId: input.corporateInvoiceId },
    });
    if (existing) {
      return existing;
    }
    const snap = input.snapshot && typeof input.snapshot === 'object' ? (input.snapshot as Record<string, unknown>) : {};
    const taxPaise = Number(snap.gstPaise ?? (snap.breakdown as { gstPaise?: number } | undefined)?.gstPaise ?? 0) || 0;
    const total = Math.max(0, input.totalPaise);
    return tx.invoice.create({
      data: {
        publicRef: this.newRef('KCI'),
        kind: input.kind,
        customerId: input.customerId,
        bookingId: input.bookingId ?? null,
        parcelId: input.parcelId ?? null,
        travelBookingId: input.travelBookingId ?? null,
        bulkBookingId: input.bulkBookingId ?? null,
        corporateInvoiceId: input.corporateInvoiceId ?? null,
        status: 'issued',
        subtotalPaise: BigInt(Math.max(0, total - taxPaise)),
        taxPaise: BigInt(taxPaise),
        totalPaise: BigInt(total),
        lines: (snap.breakdown ?? snap.lines ?? snap) as Prisma.InputJsonValue,
      },
    });
  }

  private async markEntityPending(
    tx: Prisma.TransactionClient,
    target: { parcelId?: bigint; travelBookingId?: bigint; bulkBookingId?: bigint; bookingId?: bigint },
    method: string,
  ) {
    if (target.parcelId) {
      await tx.parcelShipment.update({
        where: { id: target.parcelId },
        data: { paymentStatus: 'cod', paymentMethod: method },
      });
    }
  }

  private async applyEntityPaid(tx: Prisma.TransactionClient, payment: {
    method: string;
    intent: string;
    parcelId: bigint | null;
    travelBookingId: bigint | null;
    bulkBookingId: bigint | null;
    corporateInvoiceId: bigint | null;
  }) {
    const method = payment.method;
    if (payment.parcelId) {
      await tx.parcelShipment.update({
        where: { id: payment.parcelId },
        data: { paymentStatus: 'paid', paymentMethod: method },
      });
    }
    if (payment.travelBookingId) {
      await tx.travelBooking.update({
        where: { id: payment.travelBookingId },
        data: { paymentStatus: 'paid', paymentMethod: method, status: 'confirmed' },
      });
    }
    if (payment.bulkBookingId) {
      const bulk = await tx.bulkBooking.findUnique({ where: { id: payment.bulkBookingId } });
      if (!bulk) {
        return;
      }
      if (payment.intent === 'advance') {
        const demo = this.config.get<boolean>('demo.autoAssign') === true;
        await tx.bulkBooking.update({
          where: { id: bulk.id },
          data: {
            paymentStatus: 'advance',
            paymentMethod: method,
            status: demo ? 'assigned' : 'advance_paid',
          },
        });
      } else {
        await tx.bulkBooking.update({
          where: { id: bulk.id },
          data: { paymentStatus: 'paid', paymentMethod: method, status: 'completed' },
        });
      }
    }
    if (payment.corporateInvoiceId) {
      await tx.corporateInvoice.update({
        where: { id: payment.corporateInvoiceId },
        data: { status: 'paid' },
      });
    }
  }

  private assertOwner(actor: Actor, customerId: bigint) {
    if (actor.unrestricted) {
      return;
    }
    if (actor.userId !== customerId && actor.role !== UserRole.ADMIN && actor.role !== UserRole.SUPER_ADMIN) {
      throw new ForbiddenException('Not allowed to pay for this record');
    }
  }

  private assertCanRead(actor: Actor, customerId: bigint | null) {
    if (actor.unrestricted || actor.role === UserRole.ADMIN || actor.role === UserRole.SUPER_ADMIN) {
      return;
    }
    if (customerId && actor.userId === customerId) {
      return;
    }
    if (actor.role === UserRole.DRIVER || actor.role === UserRole.FLEET_OWNER || actor.role === UserRole.DISTRICT_HEAD) {
      return;
    }
    throw new ForbiddenException('Access denied for this payment');
  }

  private assertCanConfirmCash(
    actor: Actor,
    payment: { method: string; customerId: bigint | null },
  ) {
    if (actor.unrestricted || actor.role === UserRole.ADMIN || actor.role === UserRole.SUPER_ADMIN) {
      return;
    }
    if (
      actor.role === UserRole.DRIVER ||
      actor.role === UserRole.FLEET_OWNER ||
      actor.role === UserRole.DISTRICT_HEAD ||
      actor.role === UserRole.FRANCHISE
    ) {
      return;
    }
    throw new ForbiddenException('Cash must be confirmed on the server by driver or ops');
  }

  private webhookSecret() {
    return this.config.get<string>('payments.webhookSecret') || this.config.get<string>('jwt.secret') || '';
  }

  private newRef(prefix: string) {
    return `${prefix}${randomBytes(6).toString('hex').toUpperCase()}`.slice(0, 24);
  }
}
