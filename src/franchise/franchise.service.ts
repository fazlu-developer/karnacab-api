import { createHash } from 'crypto';
import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import {
  BookingStatus,
  FranchiseKind,
  FranchiseStatus,
  Prisma,
  UserRole,
  WalletOwnerType,
} from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { ScopeService } from '../access/scope.service';
import { Actor, canReadFranchise } from '../access/territory';
import { KycStorage } from '../kyc/kyc.storage';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';
import {
  ApplyFranchiseDto,
  CreateFranchiseFeeDto,
  CreateFranchiseRenewalDto,
  FranchiseLifecycleDto,
  PatchFranchiseTerritoryDto,
  SignFranchiseAgreementDto,
  UploadFranchiseDocumentDto,
} from './dto/franchise.dto';
import {
  canTransition,
  exclusiveSeatKey,
  FRANCHISE_DOC_TYPES,
  FRANCHISE_HIERARCHY,
  FRANCHISE_KIND_LABELS,
  FRANCHISE_STATUS_LABELS,
  holdsExclusiveSeat,
  otherActiveBlocks,
} from './franchise-status';

const ALLOWED_MIME = new Set(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
const STAFF: UserRole[] = [UserRole.ADMIN, UserRole.SUPER_ADMIN, UserRole.STATE_HEAD];

@Injectable()
export class FranchiseService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly storage: KycStorage,
    private readonly events: DomainEvents,
  ) {}

  catalog() {
    return {
      hierarchy: FRANCHISE_HIERARCHY,
      kinds: FRANCHISE_KIND_LABELS,
      statuses: FRANCHISE_STATUS_LABELS,
      documentTypes: FRANCHISE_DOC_TYPES,
      rule: 'One district has one active District Head or Exclusive District Franchise',
    };
  }

  async list(actor: Actor) {
    const rows = await this.prisma.franchise.findMany({
      where: this.scopes.franchiseWhere(actor),
      include: this.detailInclude(),
      orderBy: { createdAt: 'desc' },
      take: 200,
    });
    return { franchises: rows.filter((row) => this.mayRead(actor, row)).map((row) => this.present(row)) };
  }

  async mine(actor: Actor) {
    const rows = await this.prisma.franchise.findMany({
      where: { ownerUserId: actor.userId },
      include: this.detailInclude(),
      orderBy: { createdAt: 'desc' },
    });
    return { franchises: rows.map((row) => this.present(row)) };
  }

  async one(actor: Actor, id: bigint) {
    return this.present(await this.require(actor, id));
  }

  async apply(actor: Actor, dto: ApplyFranchiseDto) {
    const ownerUserId = await this.resolveOwner(actor, dto.ownerUserId);
    const district = await this.requireDistrict(dto.districtId);
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null && district.stateId !== actor.stateId) {
      throw new ForbiddenException('District is outside the assigned state');
    }
    const row = await this.prisma.franchise.create({
      data: {
        districtId: district.id,
        stateId: district.stateId,
        ownerUserId,
        parentUserId: actor.role === UserRole.STATE_HEAD ? actor.userId : null,
        kind: dto.kind as FranchiseKind,
        status: FranchiseStatus.APPLIED,
        tradeName: dto.tradeName,
        gstin: dto.gstin,
        pan: dto.pan,
        contactPhone: dto.contactPhone,
        feeAmountPaise: dto.feeAmountPaise ?? 0,
        notes: dto.notes,
        fees:
          dto.feeAmountPaise && dto.feeAmountPaise > 0
            ? {
                create: {
                  kind: 'application',
                  amountPaise: dto.feeAmountPaise,
                  status: 'due',
                },
              }
            : undefined,
        events: {
          create: {
            actorUserId: actor.userId,
            action: 'apply',
            toStatus: FranchiseStatus.APPLIED,
            note: `Territory district ${district.id} assigned`,
          },
        },
      },
      include: this.detailInclude(),
    });
    return this.present(row);
  }

  async setTerritory(actor: Actor, id: bigint, dto: PatchFranchiseTerritoryDto) {
    this.assertStaff(actor);
    const current = await this.require(actor, id);
    const district = await this.requireDistrict(dto.districtId);
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null && district.stateId !== actor.stateId) {
      throw new ForbiddenException('District is outside the assigned state');
    }
    if (holdsExclusiveSeat(current.status) && district.id !== current.districtId) {
      return this.present(await this.reseat(actor, current.id, district.id, district.stateId));
    }
    const row = await this.prisma.franchise.update({
      where: { id },
      data: { districtId: district.id, stateId: district.stateId },
      include: this.detailInclude(),
    });
    await this.record(id, actor.userId, 'territory', current.status, current.status, `District ${district.id}`);
    return this.present(row);
  }

  async lifecycle(actor: Actor, id: bigint, dto: FranchiseLifecycleDto) {
    this.assertStaff(actor);
    const current = await this.require(actor, id);
    if (!canTransition(current.status, dto.status)) {
      throw new BadRequestException(`Cannot move from ${current.status} to ${dto.status}`);
    }
    try {
      const row = await this.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT id FROM districts WHERE id = ${current.districtId} FOR UPDATE`;
        const next = dto.status as FranchiseStatus;
        const holdSeat = holdsExclusiveSeat(next);
        if (holdSeat) {
          const active = await tx.franchise.findFirst({
            where: { districtId: current.districtId, status: FranchiseStatus.ACTIVE },
            select: { id: true },
          });
          if (otherActiveBlocks(active?.id, current.id)) {
            throw new ConflictException('This district already has an active district head or exclusive franchise');
          }
        }
        const updated = await tx.franchise.update({
          where: { id: current.id },
          data: {
            status: next,
            activeDistrictKey: holdSeat ? exclusiveSeatKey(current.districtId) : null,
            startsOn: dto.startsOn ? new Date(dto.startsOn) : current.startsOn,
            endsOn: dto.endsOn ? new Date(dto.endsOn) : current.endsOn,
            terminatedAt: next === FranchiseStatus.TERMINATED ? new Date() : current.terminatedAt,
            terminationReason: dto.reason ?? current.terminationReason,
          },
          include: this.detailInclude(),
        });
        await tx.franchiseEvent.create({
          data: {
            franchiseId: current.id,
            actorUserId: actor.userId,
            action: 'lifecycle',
            fromStatus: current.status,
            toStatus: next,
            note: dto.reason,
          },
        });
        if (holdSeat) {
          const role =
            updated.kind === FranchiseKind.DISTRICT_HEAD ? UserRole.DISTRICT_HEAD : UserRole.FRANCHISE;
          const owner = await tx.user.findUnique({ where: { id: updated.ownerUserId }, select: { role: true } });
          if (owner && owner.role !== UserRole.ADMIN && owner.role !== UserRole.SUPER_ADMIN) {
            await tx.user.update({
              where: { id: updated.ownerUserId },
              data: { role, districtId: updated.districtId, stateId: updated.stateId, status: 'ACTIVE' },
            });
          }
          const ownerType =
            role === UserRole.DISTRICT_HEAD ? WalletOwnerType.DISTRICT_HEAD : WalletOwnerType.FRANCHISE;
          await tx.wallet.upsert({
            where: { ownerType_ownerUserId: { ownerType, ownerUserId: updated.ownerUserId } },
            create: { ownerType, ownerUserId: updated.ownerUserId, balancePaise: 0 },
            update: {},
          });
        }
        return updated;
      });
      if (row.status === FranchiseStatus.ACTIVE) {
        this.events.emit('franchise.approved', {
          franchiseId: row.id.toString(),
          ownerUserId: row.ownerUserId.toString(),
        });
      }
      return this.present(row);
    } catch (error) {
      this.rethrowSeatConflict(error);
      throw error;
    }
  }

  async uploadDocument(actor: Actor, id: bigint, dto: UploadFranchiseDocumentDto) {
    const franchise = await this.require(actor, id);
    if (!this.mayWriteDocs(actor, franchise.ownerUserId)) {
      throw new ForbiddenException('Cannot upload documents for this franchise');
    }
    if (!ALLOWED_MIME.has(dto.mime)) {
      throw new BadRequestException('Upload a JPEG, PNG, WebP, or PDF');
    }
    const buffer = this.decodeFile(dto.fileBase64);
    const ext = dto.mime === 'application/pdf' ? 'pdf' : 'jpg';
    const key = await this.storage.write(`franchise-${id.toString()}`, dto.type, buffer, ext);
    const existing = await this.prisma.franchiseDocument.findUnique({
      where: { franchiseId_type: { franchiseId: id, type: dto.type } },
    });
    if (existing) {
      await this.storage.remove(existing.storageKey);
    }
    const row = await this.prisma.franchiseDocument.upsert({
      where: { franchiseId_type: { franchiseId: id, type: dto.type } },
      create: {
        franchiseId: id,
        type: dto.type,
        mime: dto.mime,
        originalName: dto.originalName,
        storageKey: key,
        sizeBytes: buffer.length,
        checksumSha256: createHash('sha256').update(buffer).digest('hex'),
        expiresAt: dto.expiresAt ? new Date(dto.expiresAt) : null,
        status: 'under_review',
      },
      update: {
        mime: dto.mime,
        originalName: dto.originalName,
        storageKey: key,
        sizeBytes: buffer.length,
        checksumSha256: createHash('sha256').update(buffer).digest('hex'),
        expiresAt: dto.expiresAt ? new Date(dto.expiresAt) : null,
        status: 'under_review',
        rejectionReason: null,
      },
    });
    await this.prisma.franchise.update({
      where: { id },
      data: { kycStatus: 'under_review' },
    });
    return this.serializeDoc(row);
  }

  async documentFile(actor: Actor, franchiseId: bigint, documentId: bigint) {
    await this.require(actor, franchiseId);
    const doc = await this.prisma.franchiseDocument.findFirst({
      where: { id: documentId, franchiseId },
    });
    if (!doc) {
      throw new NotFoundException('Document not found');
    }
    return {
      buffer: await this.storage.read(doc.storageKey),
      mime: doc.mime,
      name: doc.originalName ?? `${doc.type}.bin`,
    };
  }

  async signAgreement(actor: Actor, id: bigint, dto: SignFranchiseAgreementDto) {
    const franchise = await this.require(actor, id);
    if (franchise.ownerUserId !== actor.userId && !STAFF.includes(actor.role)) {
      throw new ForbiddenException('Only the franchise owner can sign');
    }
    const signedAt = new Date();
    const agreement = await this.prisma.franchiseAgreement.create({
      data: {
        franchiseId: id,
        version: dto.version ?? 'v1',
        title: 'KarnaCab exclusive district franchise agreement',
        signedAt,
        expiresAt: dto.expiresAt ? new Date(dto.expiresAt) : franchise.endsOn,
        status: 'signed',
      },
    });
    await this.prisma.franchise.update({
      where: { id },
      data: { agreementStatus: 'signed' },
    });
    await this.record(id, actor.userId, 'agreement', franchise.status, franchise.status, agreement.version);
    return this.serializeAgreement(agreement);
  }

  async fees(actor: Actor, id: bigint) {
    const row = await this.require(actor, id);
    return { fees: row.fees.map((fee) => this.serializeFee(fee)) };
  }

  async addFee(actor: Actor, id: bigint, dto: CreateFranchiseFeeDto) {
    this.assertStaff(actor);
    await this.require(actor, id);
    const fee = await this.prisma.franchiseFee.create({
      data: {
        franchiseId: id,
        kind: dto.kind,
        amountPaise: dto.amountPaise,
        dueOn: dto.dueOn ? new Date(dto.dueOn) : null,
        note: dto.note,
        status: 'due',
      },
    });
    return this.serializeFee(fee);
  }

  async payFee(actor: Actor, id: bigint, feeId: bigint) {
    await this.require(actor, id);
    const fee = await this.prisma.franchiseFee.findFirst({ where: { id: feeId, franchiseId: id } });
    if (!fee) {
      throw new NotFoundException('Fee not found');
    }
    const paid = await this.prisma.franchiseFee.update({
      where: { id: feeId },
      data: { status: 'paid', paidAt: new Date() },
    });
    const due = await this.prisma.franchiseFee.count({ where: { franchiseId: id, status: 'due' } });
    if (due === 0) {
      await this.prisma.franchise.update({ where: { id }, data: { feePaidAt: new Date() } });
    }
    return this.serializeFee(paid);
  }

  async renewals(actor: Actor, id: bigint) {
    const row = await this.require(actor, id);
    return { renewals: row.renewals.map((item) => this.serializeRenewal(item)) };
  }

  async requestRenewal(actor: Actor, id: bigint, dto: CreateFranchiseRenewalDto) {
    const franchise = await this.require(actor, id);
    if (franchise.ownerUserId !== actor.userId && !STAFF.includes(actor.role)) {
      throw new ForbiddenException('Cannot renew this franchise');
    }
    const renewal = await this.prisma.franchiseRenewal.create({
      data: {
        franchiseId: id,
        periodStart: new Date(dto.periodStart),
        periodEnd: new Date(dto.periodEnd),
        status: 'pending',
      },
    });
    await this.record(id, actor.userId, 'renewal', franchise.status, franchise.status, null);
    return this.serializeRenewal(renewal);
  }

  async revenue(actor: Actor, id: bigint) {
    const row = await this.require(actor, id);
    const bookings = await this.prisma.booking.findMany({
      where: { districtId: row.districtId, status: BookingStatus.COMPLETED },
      select: { quotePaise: true },
      take: 5000,
    });
    const gross = bookings.reduce((sum, item) => sum + Number(item.quotePaise ?? 0), 0);
    return {
      districtId: row.districtId,
      completedTrips: bookings.length,
      grossPaise: gross,
      grossRupees: gross / 100,
    };
  }

  async commission(actor: Actor, id: bigint) {
    const row = await this.require(actor, id);
    const rule = await this.prisma.commissionRule.findFirst({ where: { active: true, name: 'default' } });
    return {
      platformRule: serializeCommissionPolicy(rule),
      franchisePercent: Number(row.commissionPercent),
    };
  }

  async performance(actor: Actor, id: bigint) {
    const row = await this.require(actor, id);
    const [vehicles, fleets, trips] = await Promise.all([
      this.prisma.vehicle.count({ where: { districtId: row.districtId } }),
      this.prisma.fleetOwner.count({ where: { vehicles: { some: { districtId: row.districtId } } } }),
      this.prisma.booking.count({
        where: { districtId: row.districtId, status: BookingStatus.COMPLETED },
      }),
    ]);
    return {
      districtId: row.districtId,
      vehicles,
      fleets,
      completedTrips: trips,
      kycStatus: row.kycStatus,
      agreementStatus: row.agreementStatus,
      status: row.status,
    };
  }

  async hierarchy(actor: Actor, districtId?: number) {
    const targetDistrictId = districtId ?? actor.districtId;
    if (!actor.unrestricted && actor.role === UserRole.STATE_HEAD && districtId != null) {
      const district = await this.requireDistrict(districtId);
      if (actor.stateId != null && district.stateId !== actor.stateId) {
        throw new ForbiddenException('District is outside the assigned state');
      }
    }
    if (!actor.unrestricted && (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE)) {
      if (targetDistrictId == null || (districtId != null && districtId !== actor.districtId)) {
        throw new ForbiddenException('District Head can only view the assigned district hierarchy');
      }
    }
    if (targetDistrictId == null && !actor.unrestricted && actor.role !== UserRole.STATE_HEAD) {
      throw new BadRequestException('districtId is required');
    }

    const district =
      targetDistrictId != null
        ? await this.prisma.district.findUnique({
            where: { id: targetDistrictId },
            include: { state: true },
          })
        : null;

    const stateId = district?.stateId ?? actor.stateId;
    const [stateHead, seat, fleets] = await Promise.all([
      stateId
        ? this.prisma.user.findFirst({
            where: { role: UserRole.STATE_HEAD, stateId },
            select: { id: true, name: true, email: true, stateId: true },
          })
        : Promise.resolve(null),
      targetDistrictId != null
        ? this.prisma.franchise.findFirst({
            where: { districtId: targetDistrictId, status: FranchiseStatus.ACTIVE },
            include: { owner: { select: { id: true, name: true, email: true, role: true } } },
          })
        : Promise.resolve(null),
      targetDistrictId != null
        ? this.prisma.fleetOwner.findMany({
            where: { vehicles: { some: { districtId: targetDistrictId } } },
            include: {
              user: { select: { name: true } },
              drivers: { include: { user: { select: { name: true } } }, take: 20 },
            },
            take: 50,
          })
        : Promise.resolve([]),
    ]);

    if (seat && !this.mayRead(actor, { ...seat, district: { stateId: seat.stateId } })) {
      throw new ForbiddenException('Access denied for this territory');
    }

    return {
      hierarchy: FRANCHISE_HIERARCHY,
      corporate: { label: 'KarnaCab Corporate' },
      state: district?.state ?? (stateId ? { id: stateId } : null),
      stateHead: stateHead
        ? { userId: stateHead.id.toString(), name: stateHead.name, email: stateHead.email }
        : null,
      district: district ? { id: district.id, name: district.name, stateId: district.stateId } : null,
      districtSeat: seat
        ? {
            id: seat.id.toString(),
            kind: seat.kind,
            kindLabel: FRANCHISE_KIND_LABELS[seat.kind],
            status: seat.status,
            tradeName: seat.tradeName,
            owner: {
              userId: seat.owner.id.toString(),
              name: seat.owner.name,
              role: seat.owner.role,
            },
          }
        : null,
      fleets: fleets.map((fleet) => ({
        id: fleet.id.toString(),
        tradeName: fleet.tradeName,
        ownerName: fleet.user.name,
        drivers: fleet.drivers.map((driver) => ({
          id: driver.id.toString(),
          name: driver.user.name,
        })),
      })),
    };
  }

  private async reseat(actor: Actor, id: bigint, districtId: number, stateId: number) {
    try {
      return await this.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT id FROM districts WHERE id = ${districtId} FOR UPDATE`;
        const active = await tx.franchise.findFirst({
          where: { districtId, status: FranchiseStatus.ACTIVE },
          select: { id: true },
        });
        if (otherActiveBlocks(active?.id, id)) {
          throw new ConflictException('This district already has an active district head or exclusive franchise');
        }
        return tx.franchise.update({
          where: { id },
          data: {
            districtId,
            stateId,
            activeDistrictKey: exclusiveSeatKey(districtId),
          },
          include: this.detailInclude(),
        });
      });
    } catch (error) {
      this.rethrowSeatConflict(error);
      throw error;
    }
  }

  private async require(actor: Actor, id: bigint) {
    const row = await this.prisma.franchise.findUnique({
      where: { id },
      include: this.detailInclude(),
    });
    if (!row) {
      throw new NotFoundException('Franchise not found');
    }
    this.scopes.assertFranchise(actor, {
      ownerUserId: row.ownerUserId,
      districtId: row.districtId,
      districtStateId: row.district.stateId,
    });
    return row;
  }

  private mayRead(
    actor: Actor,
    row: { ownerUserId: bigint; districtId: number; district?: { stateId: number }; stateId?: number },
  ) {
    return canReadFranchise(actor, {
      ownerUserId: row.ownerUserId,
      districtId: row.districtId,
      districtStateId: row.district?.stateId ?? row.stateId ?? null,
    });
  }

  private mayWriteDocs(actor: Actor, ownerUserId: bigint) {
    return actor.unrestricted || STAFF.includes(actor.role) || actor.userId === ownerUserId;
  }

  private assertStaff(actor: Actor) {
    if (!STAFF.includes(actor.role) && !actor.unrestricted) {
      throw new ForbiddenException('State head or admin required');
    }
  }

  private async resolveOwner(actor: Actor, ownerUserId?: string) {
    if (!ownerUserId) {
      return actor.userId;
    }
    if (!STAFF.includes(actor.role) && !actor.unrestricted) {
      throw new ForbiddenException('Cannot assign another owner');
    }
    const owner = await this.prisma.user.findUnique({ where: { id: BigInt(ownerUserId) }, select: { id: true } });
    if (!owner) {
      throw new NotFoundException('Owner user not found');
    }
    return owner.id;
  }

  private async requireDistrict(districtId: number) {
    const district = await this.prisma.district.findUnique({
      where: { id: districtId },
      select: { id: true, stateId: true, name: true },
    });
    if (!district) {
      throw new BadRequestException('District territory must be an existing district');
    }
    return district;
  }

  private rethrowSeatConflict(error: unknown) {
    if (error instanceof ConflictException) {
      return;
    }
    if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === 'P2002') {
      throw new ConflictException('This district already has an active district head or exclusive franchise');
    }
  }

  private async record(
    franchiseId: bigint,
    actorUserId: bigint,
    action: string,
    fromStatus: string,
    toStatus: string,
    note: string | null,
  ) {
    await this.prisma.franchiseEvent.create({
      data: { franchiseId, actorUserId, action, fromStatus, toStatus, note },
    });
  }

  private decodeFile(raw: string) {
    const cleaned = raw.includes(',') ? raw.slice(raw.indexOf(',') + 1) : raw;
    const buffer = Buffer.from(cleaned, 'base64');
    if (!buffer.length) {
      throw new BadRequestException('File is empty');
    }
    return buffer;
  }

  private detailInclude() {
    return {
      district: { select: { id: true, name: true, stateId: true, state: { select: { name: true, code: true } } } },
      owner: { select: { id: true, name: true, email: true, role: true } },
      documents: true,
      agreements: { orderBy: { createdAt: 'desc' as const }, take: 5 },
      fees: { orderBy: { createdAt: 'desc' as const }, take: 20 },
      renewals: { orderBy: { createdAt: 'desc' as const }, take: 10 },
    };
  }

  private present(row: Awaited<ReturnType<FranchiseService['require']>>) {
    return {
      id: row.id.toString(),
      kind: row.kind,
      kindLabel: FRANCHISE_KIND_LABELS[row.kind],
      status: row.status,
      statusLabel: FRANCHISE_STATUS_LABELS[row.status],
      tradeName: row.tradeName,
      gstin: row.gstin,
      pan: row.pan,
      contactPhone: row.contactPhone,
      kycStatus: row.kycStatus,
      agreementStatus: row.agreementStatus,
      feeAmountPaise: Number(row.feeAmountPaise),
      feePaidAt: row.feePaidAt?.toISOString() ?? null,
      commissionPercent: Number(row.commissionPercent),
      startsOn: row.startsOn?.toISOString() ?? null,
      endsOn: row.endsOn?.toISOString() ?? null,
      terminatedAt: row.terminatedAt?.toISOString() ?? null,
      terminationReason: row.terminationReason,
      notes: row.notes,
      exclusiveSeat: holdsExclusiveSeat(row.status),
      territory: {
        districtId: row.districtId,
        districtName: row.district.name,
        stateId: row.stateId,
        stateName: row.district.state.name,
      },
      owner: {
        userId: row.owner.id.toString(),
        name: row.owner.name,
        email: row.owner.email,
        role: row.owner.role,
      },
      documents: row.documents.map((doc) => this.serializeDoc(doc)),
      agreements: row.agreements.map((item) => this.serializeAgreement(item)),
      fees: row.fees.map((fee) => this.serializeFee(fee)),
      renewals: row.renewals.map((item) => this.serializeRenewal(item)),
    };
  }

  private serializeDoc(doc: {
    id: bigint;
    type: string;
    status: string;
    originalName: string | null;
    mime: string;
    expiresAt: Date | null;
    rejectionReason: string | null;
  }) {
    return {
      id: doc.id.toString(),
      type: doc.type,
      status: doc.status,
      originalName: doc.originalName,
      mime: doc.mime,
      expiresAt: doc.expiresAt?.toISOString() ?? null,
      rejectionReason: doc.rejectionReason,
    };
  }

  private serializeAgreement(row: {
    id: bigint;
    version: string;
    title: string;
    signedAt: Date | null;
    expiresAt: Date | null;
    status: string;
  }) {
    return {
      id: row.id.toString(),
      version: row.version,
      title: row.title,
      signedAt: row.signedAt?.toISOString() ?? null,
      expiresAt: row.expiresAt?.toISOString() ?? null,
      status: row.status,
    };
  }

  private serializeFee(row: {
    id: bigint;
    kind: string;
    amountPaise: bigint;
    status: string;
    dueOn: Date | null;
    paidAt: Date | null;
    note: string | null;
  }) {
    return {
      id: row.id.toString(),
      kind: row.kind,
      amountPaise: Number(row.amountPaise),
      status: row.status,
      dueOn: row.dueOn?.toISOString() ?? null,
      paidAt: row.paidAt?.toISOString() ?? null,
      note: row.note,
    };
  }

  private serializeRenewal(row: {
    id: bigint;
    periodStart: Date;
    periodEnd: Date;
    status: string;
  }) {
    return {
      id: row.id.toString(),
      periodStart: row.periodStart.toISOString(),
      periodEnd: row.periodEnd.toISOString(),
      status: row.status,
    };
  }
}
