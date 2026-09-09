import { randomInt } from 'crypto';
import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { Actor } from '../access/territory';
import { hasPermission, Permission } from '../access/permissions';
import { DomainEvents } from '../common/domain-events.service';
import { NotificationsService } from '../notify/notifications.service';
import {
  SUPPORT_CATEGORIES,
  SUPPORT_KINDS,
  SUPPORT_PRIORITIES,
  SUPPORT_STATUSES,
} from '../notify/notify.catalog';
import { SupportStorage } from './support.storage';
import { CreateSupportTicketDto, ResolveTicketDto, SupportAttachmentDto } from './dto/support.dto';

const ATTACH_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'image/jpg'];

const TRANSITIONS: Record<string, string[]> = {
  open: ['assigned'],
  assigned: ['in_progress'],
  in_progress: ['resolved'],
  resolved: ['closed'],
  closed: [],
};

const LEGACY: Record<string, string> = { pending: 'in_progress', waiting: 'in_progress' };

@Injectable()
export class SupportService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly storage: SupportStorage,
    private readonly notifications: NotificationsService,
    private readonly events: DomainEvents,
  ) {}

  catalog() {
    return {
      kinds: [...SUPPORT_KINDS],
      statuses: [...SUPPORT_STATUSES],
      flow: 'Open → Assigned → In Progress → Resolved → Closed',
      categories: [...SUPPORT_CATEGORIES],
      priorities: [...SUPPORT_PRIORITIES],
      audiences: ['customer', 'driver', 'ops'],
      attachments: ATTACH_MIME,
      fields: [
        'ticketId',
        'user',
        'booking',
        'category',
        'subject',
        'description',
        'attachments',
        'priority',
        'status',
        'assignedAgent',
        'resolution',
        'createdAt',
        'closedAt',
      ],
      note: 'Users open tickets. Agents assign, work, resolve, then close. History records every status change, reply, and attachment.',
    };
  }

  async faqs(audience = 'customer') {
    const rows = await this.prisma.supportFaq.findMany({
      where: { active: true, audience },
      orderBy: { sortOrder: 'asc' },
    });
    return {
      faqs: rows.map((row) => ({
        id: row.id.toString(),
        question: row.question,
        answer: row.answer,
        q: row.question,
        a: row.answer,
      })),
    };
  }

  async create(actor: Actor, dto: CreateSupportTicketDto) {
    const kind = dto.kind ?? 'support';
    const description = (dto.description ?? dto.message ?? '').trim();
    if (description.length < 4) {
      throw new BadRequestException('Enter a description');
    }
    const category = dto.category ?? 'other';
    const priority = dto.priority ?? 'medium';
    let bookingId: bigint | null = null;
    let districtId = actor.districtId;
    let safetyIncidentId: bigint | null = null;
    if (dto.bookingId) {
      const booking = await this.prisma.booking.findUnique({
        where: { id: BigInt(dto.bookingId) },
        select: { id: true, customerId: true, driverId: true, districtId: true, publicRef: true },
      });
      if (!booking) {
        throw new BadRequestException('Booking not found');
      }
      const driver = actor.driverId
        ? await this.prisma.driver.findUnique({ where: { id: actor.driverId }, select: { id: true } })
        : null;
      const owns =
        booking.customerId === actor.userId ||
        (driver && booking.driverId === driver.id) ||
        actor.unrestricted;
      if (!owns) {
        throw new ForbiddenException('You can only link your own booking');
      }
      bookingId = booking.id;
      districtId = booking.districtId ?? districtId;
    }
    if (kind === 'complaint' && bookingId) {
      const incident = await this.prisma.safetyIncident.create({
        data: {
          publicRef: `SI${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
          type: 'complaint',
          status: 'open',
          description,
          bookingId,
          reporterUserId: actor.userId,
          districtId,
        },
      });
      safetyIncidentId = incident.id;
    }
    const ticket = await this.prisma.supportTicket.create({
      data: {
        publicRef: `ST${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        userId: actor.userId,
        kind,
        status: 'open',
        subject: dto.subject.trim(),
        description,
        category,
        priority,
        bookingId,
        safetyIncidentId,
        districtId,
        messages: {
          create: {
            authorId: actor.userId,
            fromStaff: false,
            body: description,
          },
        },
        events: {
          create: {
            actorUserId: actor.userId,
            action: 'created',
            toStatus: 'open',
            note: dto.subject.trim(),
          },
        },
      },
      include: this.include(),
    });
    this.events.emit('support.updated', {
      userId: actor.userId.toString(),
      ticketId: ticket.id.toString(),
      ref: ticket.publicRef,
      status: ticket.status,
    });
    await this.notifications.notifyOps({
      title: kind === 'complaint' ? 'New complaint' : 'New support ticket',
      body: `${ticket.publicRef}: ${ticket.subject}`,
      kind: 'complaints',
      districtId,
      entity: { type: 'ticket', id: ticket.id.toString() },
    });
    return this.present(ticket, actor);
  }

  async listMine(actor: Actor) {
    const rows = await this.prisma.supportTicket.findMany({
      where: { userId: actor.userId },
      orderBy: { createdAt: 'desc' },
      take: 50,
      include: this.include(),
    });
    return { tickets: rows.map((row) => this.present(row, actor)) };
  }

  async one(actor: Actor, id: bigint) {
    const row = await this.prisma.supportTicket.findUnique({ where: { id }, include: this.include() });
    if (!row) {
      throw new NotFoundException('Ticket not found');
    }
    this.assertRead(actor, row);
    return this.present(row, actor, true);
  }

  async assign(actor: Actor, id: bigint, agentId?: string) {
    if (!this.isStaff(actor)) {
      throw new ForbiddenException('Support access required');
    }
    const ticket = await this.prisma.supportTicket.findUnique({ where: { id } });
    if (!ticket) {
      throw new NotFoundException('Ticket not found');
    }
    this.assertRead(actor, ticket);
    const from = this.normalize(ticket.status);
    const agent = agentId ? BigInt(agentId) : actor.userId;
    const data: { assignedAgentId: bigint; status?: string } = { assignedAgentId: agent };
    let to = from;
    if (from === 'open') {
      this.assertAdvance(from, 'assigned');
      data.status = 'assigned';
      to = 'assigned';
    }
    await this.prisma.supportTicket.update({ where: { id }, data });
    await this.recordEvent(id, actor.userId, 'assigned', from, to, `Agent ${agent.toString()}`);
    return this.one(actor, id);
  }

  async addMessage(actor: Actor, id: bigint, body: string, staff = false) {
    const ticket = await this.prisma.supportTicket.findUnique({ where: { id } });
    if (!ticket) {
      throw new NotFoundException('Ticket not found');
    }
    this.assertRead(actor, ticket);
    const asStaff = staff || this.isStaff(actor);
    if (staff && !this.isStaff(actor)) {
      throw new ForbiddenException('Support access required');
    }
    const status = this.normalize(ticket.status);
    if (['resolved', 'closed'].includes(status) && !asStaff) {
      throw new BadRequestException('This ticket is already resolved');
    }
    await this.prisma.supportMessage.create({
      data: {
        ticketId: id,
        authorId: actor.userId,
        fromStaff: asStaff,
        body: body.trim(),
      },
    });
    await this.recordEvent(id, actor.userId, 'message', status, status, asStaff ? 'staff' : 'user');
    if (asStaff && status === 'open') {
      return this.assign(actor, id);
    }
    if (asStaff && status === 'assigned') {
      await this.prisma.supportTicket.update({ where: { id }, data: { status: 'in_progress' } });
      await this.recordEvent(id, actor.userId, 'status', 'assigned', 'in_progress', 'Reply');
    }
    if (asStaff) {
      this.events.emit('support.updated', {
        userId: ticket.userId.toString(),
        ticketId: id.toString(),
        ref: ticket.publicRef,
        status: 'in_progress',
      });
    }
    return this.one(actor, id);
  }

  async resolve(actor: Actor, id: bigint, dto: ResolveTicketDto) {
    if (!this.isStaff(actor)) {
      throw new ForbiddenException('Support access required');
    }
    const ticket = await this.prisma.supportTicket.findUnique({ where: { id } });
    if (!ticket) {
      throw new NotFoundException('Ticket not found');
    }
    this.assertRead(actor, ticket);
    const from = this.normalize(ticket.status);
    const to = this.normalize(dto.status);
    this.assertAdvance(from, to);
    const resolution = (dto.resolution ?? dto.message ?? '').trim();
    if (dto.message?.trim()) {
      await this.prisma.supportMessage.create({
        data: {
          ticketId: id,
          authorId: actor.userId,
          fromStaff: true,
          body: dto.message.trim(),
        },
      });
    } else if (to === 'resolved' && resolution) {
      await this.prisma.supportMessage.create({
        data: {
          ticketId: id,
          authorId: actor.userId,
          fromStaff: true,
          body: resolution,
        },
      });
    }
    if (to === 'resolved' && !resolution) {
      throw new BadRequestException('Record a resolution before resolving');
    }
    await this.prisma.supportTicket.update({
      where: { id },
      data: {
        status: to,
        ...(to === 'assigned' && !ticket.assignedAgentId ? { assignedAgentId: actor.userId } : {}),
        ...(to === 'resolved'
          ? { resolution, resolvedAt: new Date() }
          : {}),
        ...(to === 'closed' ? { closedAt: new Date(), resolvedAt: ticket.resolvedAt ?? new Date() } : {}),
      },
    });
    await this.recordEvent(id, actor.userId, 'status', from, to, resolution || null);
    const updated = await this.prisma.supportTicket.findUniqueOrThrow({ where: { id } });
    this.events.emit('support.updated', {
      userId: updated.userId.toString(),
      ticketId: id.toString(),
      ref: updated.publicRef,
      status: to,
    });
    return this.one(actor, id);
  }

  async attach(actor: Actor, id: bigint, dto: SupportAttachmentDto) {
    await this.one(actor, id);
    if (!ATTACH_MIME.includes(dto.mime)) {
      throw new BadRequestException('Attach a JPEG, PNG, WebP, or PDF');
    }
    const raw = dto.fileBase64.includes(',') ? dto.fileBase64.split(',')[1] : dto.fileBase64;
    const buffer = Buffer.from(raw, 'base64');
    if (!buffer.length || buffer.length > 2 * 1024 * 1024) {
      throw new BadRequestException('Attachment must be under 2 MB');
    }
    const mime = dto.mime === 'image/jpg' ? 'image/jpeg' : dto.mime;
    const ext = mime === 'application/pdf' ? 'pdf' : mime.split('/')[1] || 'bin';
    const key = await this.storage.write(id.toString(), buffer, ext);
    const row = await this.prisma.supportAttachment.create({
      data: {
        ticketId: id,
        storageKey: key,
        mime,
        originalName: (dto.fileName ?? `attachment.${ext}`).slice(0, 160),
        bytes: buffer.length,
      },
    });
    await this.recordEvent(id, actor.userId, 'attachment', null, null, row.originalName);
    return { id: row.id.toString(), mime: row.mime, name: row.originalName, bytes: row.bytes };
  }

  async attachmentFile(actor: Actor, ticketId: bigint, attachmentId: bigint) {
    await this.one(actor, ticketId);
    const row = await this.prisma.supportAttachment.findUnique({ where: { id: attachmentId } });
    if (!row || row.ticketId !== ticketId) {
      throw new NotFoundException('Attachment not found');
    }
    return { bytes: await this.storage.read(row.storageKey), mime: row.mime, name: row.originalName };
  }

  async queue(actor: Actor, status?: string, kind?: string) {
    if (!this.isStaff(actor)) {
      throw new ForbiddenException('Support access required');
    }
    const openQueue = ['open', 'assigned', 'in_progress', 'pending', 'waiting'];
    const rows = await this.prisma.supportTicket.findMany({
      where: {
        ...(status ? { status: this.normalize(status) === status ? status : this.normalize(status) } : { status: { in: openQueue } }),
        ...(kind ? { kind } : {}),
        ...(actor.unrestricted || actor.role === UserRole.ADMIN || actor.role === UserRole.SUPER_ADMIN
          ? {}
          : actor.districtId
            ? { districtId: actor.districtId }
            : {}),
      },
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: this.include(),
    });
    return { tickets: rows.map((row) => this.present(row, actor)) };
  }

  async openFromSafety(actor: Actor, input: { incidentId: bigint; bookingId?: bigint | null; description: string; districtId?: number | null }) {
    const existing = await this.prisma.supportTicket.findFirst({
      where: { safetyIncidentId: input.incidentId },
    });
    if (existing) {
      return existing.id.toString();
    }
    const ticket = await this.prisma.supportTicket.create({
      data: {
        publicRef: `ST${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        userId: actor.userId,
        kind: 'complaint',
        status: 'open',
        subject: 'Booking complaint',
        description: input.description,
        category: 'safety',
        priority: 'high',
        bookingId: input.bookingId ?? undefined,
        safetyIncidentId: input.incidentId,
        districtId: input.districtId ?? actor.districtId,
        messages: {
          create: { authorId: actor.userId, fromStaff: false, body: input.description },
        },
        events: {
          create: { actorUserId: actor.userId, action: 'created', toStatus: 'open', note: 'Booking complaint' },
        },
      },
    });
    await this.notifications.notifyOps({
      title: 'New complaint',
      body: ticket.publicRef,
      kind: 'complaints',
      districtId: input.districtId ?? actor.districtId,
      entity: { type: 'ticket', id: ticket.id.toString() },
    });
    return ticket.id.toString();
  }

  private isStaff(actor: Actor) {
    return hasPermission(actor.role, Permission.SafetyWrite) || actor.unrestricted;
  }

  private assertRead(actor: Actor, row: { userId: bigint; districtId: number | null }) {
    if (row.userId === actor.userId || this.isStaff(actor) || actor.unrestricted) {
      return;
    }
    throw new ForbiddenException('Ticket not found');
  }

  private normalize(status: string) {
    const value = status.toLowerCase().trim();
    if (LEGACY[value]) {
      return LEGACY[value];
    }
    return (SUPPORT_STATUSES as readonly string[]).includes(value) ? value : 'open';
  }

  private assertAdvance(from: string, to: string) {
    if (!TRANSITIONS[from]?.includes(to)) {
      throw new BadRequestException('Next status must follow Open → Assigned → In Progress → Resolved → Closed');
    }
  }

  private async recordEvent(
    ticketId: bigint,
    actorUserId: bigint,
    action: string,
    fromStatus: string | null,
    toStatus: string | null,
    note: string | null,
  ) {
    await this.prisma.supportTicketEvent.create({
      data: {
        ticketId,
        actorUserId,
        action,
        fromStatus,
        toStatus,
        note: note ? note.slice(0, 500) : null,
      },
    });
  }

  private include() {
    return {
      messages: { orderBy: { createdAt: 'asc' as const }, take: 50 },
      attachments: { orderBy: { createdAt: 'desc' as const }, take: 10 },
      events: { orderBy: { createdAt: 'asc' as const }, take: 80 },
      booking: { select: { id: true, publicRef: true } },
      assignedAgent: { select: { id: true, name: true } },
      user: { select: { id: true, name: true } },
    };
  }

  private present(
    row: {
      id: bigint;
      publicRef: string;
      userId: bigint;
      kind: string;
      status: string;
      subject: string;
      description?: string;
      category?: string;
      priority?: string;
      resolution?: string | null;
      assignedAgentId?: bigint | null;
      bookingId: bigint | null;
      safetyIncidentId: bigint | null;
      resolvedAt: Date | null;
      closedAt?: Date | null;
      createdAt: Date;
      messages?: Array<{ id: bigint; fromStaff: boolean; body: string; createdAt: Date }>;
      attachments?: Array<{ id: bigint; mime: string; originalName: string; bytes: number; createdAt?: Date }>;
      events?: Array<{
        id: bigint;
        action: string;
        fromStatus: string | null;
        toStatus: string | null;
        note: string | null;
        createdAt: Date;
      }>;
      booking?: { id: bigint; publicRef: string } | null;
      assignedAgent?: { id: bigint; name: string } | null;
      user?: { id: bigint; name: string } | null;
    },
    actor: Actor,
    detail = false,
  ) {
    const staff = this.isStaff(actor);
    const status = this.normalize(row.status);
    const messages = (row.messages ?? []).map((item) => ({
      id: item.id.toString(),
      fromStaff: item.fromStaff,
      body: item.body,
      createdAt: item.createdAt.toISOString(),
      kind: 'message' as const,
    }));
    const attachments = (row.attachments ?? []).map((item) => ({
      id: item.id.toString(),
      mime: item.mime,
      name: item.originalName,
      bytes: item.bytes,
      createdAt: item.createdAt?.toISOString(),
      kind: 'attachment' as const,
    }));
    const events = (row.events ?? []).map((item) => ({
      id: `e${item.id.toString()}`,
      kind: 'event' as const,
      action: item.action,
      fromStatus: item.fromStatus,
      toStatus: item.toStatus,
      note: item.note,
      createdAt: item.createdAt.toISOString(),
    }));
    const history = [...events, ...messages, ...attachments].sort((a, b) =>
      (a.createdAt ?? '').localeCompare(b.createdAt ?? ''),
    );
    return {
      id: row.id.toString(),
      ticketId: row.publicRef,
      userId: row.userId.toString(),
      user: { id: row.userId.toString(), name: row.user?.name ?? null },
      publicRef: row.publicRef,
      kind: row.kind,
      category: row.category ?? 'other',
      priority: row.priority ?? 'medium',
      status,
      subject: row.subject,
      description: row.description || row.subject,
      bookingId: row.bookingId?.toString() ?? null,
      booking: row.booking
        ? { id: row.booking.id.toString(), publicRef: row.booking.publicRef }
        : null,
      bookingRef: row.booking?.publicRef ?? null,
      assignedAgent: row.assignedAgent
        ? { id: row.assignedAgent.id.toString(), name: row.assignedAgent.name }
        : null,
      resolution: staff || status === 'resolved' || status === 'closed' ? (row.resolution ?? null) : null,
      safetyIncidentId: row.safetyIncidentId?.toString() ?? null,
      resolvedAt: row.resolvedAt?.toISOString() ?? null,
      closedAt: row.closedAt?.toISOString() ?? null,
      createdAt: row.createdAt.toISOString(),
      message: row.messages?.[0]?.body ?? row.description ?? row.subject,
      messages,
      attachments,
      history: detail ? history : undefined,
      nextStatuses: staff && detail ? TRANSITIONS[status] ?? [] : undefined,
      canReply: true,
      canResolve: staff,
    };
  }
}
