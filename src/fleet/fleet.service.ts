import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { createHash } from 'crypto';
import { hash } from 'bcrypt';
import { BookingStatus, LedgerDirection, Prisma, UserRole, UserStatus, WalletOwnerType } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { KycService } from '../kyc/kyc.service';
import { KycStorage } from '../kyc/kyc.storage';
import { WalletsService } from '../wallets/wallets.service';
import { serializeCommissionPolicy } from '../ride-engine/commission.engine';
import { ACTIVE_RIDE_STATUSES } from '../drivers/driver-duty';
import { alertsForDocument } from '../drivers/driver-document-alerts';
import { ALLOWED_MIME } from '../kyc/kyc.types';
import {
  FLEET_LOCKED_STATUSES,
  FLEET_VEHICLE_STATUS_LABELS,
  VEHICLE_DOC_LABELS,
  VehicleDocType,
  normalizeFleetVehicleStatus,
  resolveFleetVehicleStatus,
} from './fleet-vehicle-status';
import {
  AssignFleetDriverDto,
  CreateFleetDriverDto,
  CreateFleetVehicleDto,
  PatchFleetVehicleDto,
  UnassignFleetDriverDto,
  UploadFleetVehicleDocumentDto,
} from './dto/fleet.dto';

@Injectable()
export class FleetService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly kyc: KycService,
    private readonly storage: KycStorage,
    private readonly wallets: WalletsService,
  ) {}

  async overview(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const [vehicles, drivers, tripsToday, commission] = await Promise.all([
      this.prisma.vehicle.findMany({
        where: { fleetOwnerId: fleet.id },
        include: { driver: { include: { user: { select: { name: true } } } }, documents: true },
      }),
      this.prisma.driver.count({ where: { fleetOwnerId: fleet.id } }),
      this.prisma.booking.count({
        where: {
          vehicle: { fleetOwnerId: fleet.id },
          status: BookingStatus.COMPLETED,
          updatedAt: { gte: startOfTodayIst() },
        },
      }),
      this.prisma.commissionRule.findFirst({ where: { active: true, name: 'default' } }),
    ]);
    const presented = await this.presentVehicles(vehicles);
    return {
      id: fleet.id.toString(),
      tradeName: fleet.tradeName,
      gstin: fleet.gstin,
      vehicleCount: vehicles.length,
      driverCount: drivers,
      tripsToday,
      commissionPercent: Number(commission?.percent ?? 10),
      vehicles: presented,
    };
  }

  async vehicles(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const rows = await this.prisma.vehicle.findMany({
      where: { fleetOwnerId: fleet.id },
      include: {
        driver: { include: { user: { select: { name: true, phone: true } } } },
        documents: true,
      },
      orderBy: { updatedAt: 'desc' },
    });
    return { vehicles: await this.presentVehicles(rows) };
  }

  async vehicle(actor: Actor, vehicleId: bigint) {
    const row = await this.requireVehicle(actor, vehicleId);
    const [presented] = await this.presentVehicles([row]);
    return presented;
  }

  async addVehicle(actor: Actor, dto: CreateFleetVehicleDto) {
    const fleet = await this.requireFleet(actor);
    const districtId = dto.districtId ?? (await this.ownerDistrict(actor.userId));
    if (!districtId) {
      throw new BadRequestException('Set a district on the fleet owner profile first');
    }
    const registrationNo = dto.registrationNo.replace(/\s+/g, '').toUpperCase();
    try {
      const row = await this.prisma.vehicle.create({
        data: {
          fleetOwnerId: fleet.id,
          districtId,
          category: dto.category,
          registrationNo,
          brand: dto.brand?.trim() || null,
          model: dto.model?.trim() || null,
          year: dto.year ?? null,
          color: dto.color?.trim() || null,
          fuel: dto.fuel?.trim() || null,
          status: 'available',
        },
        include: { driver: { include: { user: true } }, documents: true },
      });
      const [presented] = await this.presentVehicles([row]);
      return presented;
    } catch (error) {
      if (error instanceof Prisma.PrismaClientKnownRequestError && error.code === 'P2002') {
        throw new ConflictException('Registration number already exists');
      }
      throw error;
    }
  }

  async patchVehicle(actor: Actor, vehicleId: bigint, dto: PatchFleetVehicleDto) {
    await this.requireVehicle(actor, vehicleId);
    const row = await this.prisma.vehicle.update({
      where: { id: vehicleId },
      data: {
        ...(dto.status ? { status: dto.status } : {}),
        ...(dto.brand !== undefined ? { brand: dto.brand } : {}),
        ...(dto.model !== undefined ? { model: dto.model } : {}),
        ...(dto.year !== undefined ? { year: dto.year } : {}),
        ...(dto.color !== undefined ? { color: dto.color } : {}),
      },
      include: { driver: { include: { user: true } }, documents: true },
    });
    const [presented] = await this.presentVehicles([row]);
    return presented;
  }

  async uploadVehicleDocument(actor: Actor, vehicleId: bigint, dto: UploadFleetVehicleDocumentDto) {
    await this.requireVehicle(actor, vehicleId);
    if (!ALLOWED_MIME.has(dto.mime)) {
      throw new BadRequestException('Upload a JPEG, PNG, WebP, or PDF');
    }
    const buffer = this.decodeFile(dto.fileBase64);
    const ext = dto.mime === 'application/pdf' ? 'pdf' : 'jpg';
    const key = await this.storage.write(`vehicle-${vehicleId.toString()}`, dto.type, buffer, ext);
    const existing = await this.prisma.vehicleDocument.findUnique({
      where: { vehicleId_type: { vehicleId, type: dto.type } },
    });
    if (existing) {
      await this.storage.remove(existing.storageKey);
    }
    const row = await this.prisma.vehicleDocument.upsert({
      where: { vehicleId_type: { vehicleId, type: dto.type } },
      create: {
        vehicleId,
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
    return this.serializeVehicleDoc(row);
  }

  async vehicleDocumentFile(actor: Actor, vehicleId: bigint, documentId: bigint) {
    await this.requireVehicle(actor, vehicleId);
    const doc = await this.prisma.vehicleDocument.findFirst({
      where: { id: documentId, vehicleId },
    });
    if (!doc) {
      throw new NotFoundException('Document not found');
    }
    return {
      buffer: await this.storage.read(doc.storageKey),
      mime: doc.mime,
      name: doc.originalName || `${doc.type}.bin`,
    };
  }

  async drivers(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const rows = await this.prisma.driver.findMany({
      where: { fleetOwnerId: fleet.id },
      include: {
        user: { select: { name: true, phone: true, email: true, status: true } },
        vehicles: true,
        documents: true,
      },
      orderBy: { updatedAt: 'desc' },
    });
    return { drivers: rows.map((row) => this.serializeDriver(row)) };
  }

  async driver(actor: Actor, driverId: bigint) {
    const driver = await this.requireFleetDriver(actor, driverId);
    const [kyc, trips, earnings] = await Promise.all([
      this.kyc.snapshot(driver.userId),
      this.prisma.booking.findMany({
        where: { driverId: driver.id },
        orderBy: { updatedAt: 'desc' },
        take: 20,
      }),
      this.driverEarnings(driver.userId),
    ]);
    return {
      ...this.serializeDriver(driver),
      documents: kyc.documents,
      documentAlerts: kyc.documentAlerts,
      trips: trips.map((row) => this.serializeTrip(row)),
      earnings,
    };
  }

  async addDriver(actor: Actor, dto: CreateFleetDriverDto) {
    const fleet = await this.requireFleet(actor);
    const email = dto.email.toLowerCase();
    const existing = await this.prisma.user.findFirst({
      where: { OR: [{ email }, { phone: dto.phone }] },
      include: { driver: true },
    });
    if (existing?.driver?.fleetOwnerId && existing.driver.fleetOwnerId !== fleet.id) {
      throw new ConflictException('Driver already belongs to another fleet');
    }
    if (existing && existing.role !== UserRole.DRIVER) {
      throw new ConflictException('That email or phone is already registered');
    }
    if (existing?.driver) {
      const driver = await this.prisma.driver.update({
        where: { id: existing.driver.id },
        data: { fleetOwnerId: fleet.id, city: dto.city ?? existing.driver.city },
        include: { user: true, vehicles: true, documents: true },
      });
      return this.serializeDriver(driver);
    }
    const user = await this.prisma.user.create({
      data: {
        role: UserRole.DRIVER,
        status: UserStatus.ACTIVE,
        name: dto.name.trim(),
        email,
        phone: dto.phone,
        passwordHash: await hash(dto.password, 10),
        driver: {
          create: {
            fleetOwnerId: fleet.id,
            licenseNo: dto.licenseNo?.trim() || 'PENDING',
            city: dto.city ?? null,
            kycStatus: 'pending',
            dutyStatus: 'offline',
            online: false,
          },
        },
        wallets: { create: { ownerType: WalletOwnerType.DRIVER, balancePaise: 0 } },
      },
      include: { driver: { include: { user: true, vehicles: true, documents: true } } },
    });
    return this.serializeDriver(user.driver!);
  }

  async assign(actor: Actor, driverId: bigint, dto: AssignFleetDriverDto) {
    const driver = await this.requireFleetDriver(actor, driverId);
    const vehicle = await this.requireVehicle(actor, BigInt(dto.vehicleId));
    if (FLEET_LOCKED_STATUSES.has(normalizeFleetVehicleStatus(vehicle.status))) {
      throw new BadRequestException('Vehicle is in maintenance or suspended');
    }
    await this.prisma.$transaction([
      this.prisma.vehicle.updateMany({
        where: { driverId: driver.id, fleetOwnerId: driver.fleetOwnerId, id: { not: vehicle.id } },
        data: { driverId: null, status: 'available' },
      }),
      this.prisma.vehicle.update({
        where: { id: vehicle.id },
        data: { driverId: driver.id, status: vehicle.status === 'offline' ? 'available' : vehicle.status },
      }),
    ]);
    return this.driver(actor, driverId);
  }

  async unassign(actor: Actor, driverId: bigint, dto: UnassignFleetDriverDto) {
    const driver = await this.requireFleetDriver(actor, driverId);
    await this.prisma.vehicle.updateMany({
      where: {
        fleetOwnerId: driver.fleetOwnerId,
        driverId: driver.id,
        ...(dto.vehicleId ? { id: BigInt(dto.vehicleId) } : {}),
      },
      data: { driverId: null, status: 'available' },
    });
    return this.driver(actor, driverId);
  }

  async trips(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const rows = await this.prisma.booking.findMany({
      where: { vehicle: { fleetOwnerId: fleet.id } },
      orderBy: { updatedAt: 'desc' },
      take: 80,
      include: {
        driver: { include: { user: { select: { name: true } } } },
        vehicle: { select: { registrationNo: true } },
      },
    });
    return { trips: rows.map((row) => this.serializeTrip(row)) };
  }

  async trip(actor: Actor, bookingId: bigint) {
    const fleet = await this.requireFleet(actor);
    const row = await this.prisma.booking.findFirst({
      where: { id: bookingId, vehicle: { fleetOwnerId: fleet.id } },
      include: {
        driver: { include: { user: { select: { name: true, phone: true } } } },
        vehicle: true,
        customer: { select: { name: true } },
      },
    });
    if (!row) {
      throw new NotFoundException('Trip not found');
    }
    this.scopes.assertBooking(actor, {
      customerId: row.customerId,
      driverId: row.driverId,
      vehicleFleetOwnerId: row.vehicle?.fleetOwnerId ?? null,
      districtId: row.districtId,
      districtStateId: null,
      status: row.status,
    });
    return {
      ...this.serializeTrip(row),
      customerName: row.customer.name,
      driverPhone: row.driver?.user.phone ?? null,
      quoteSnapshot: row.quoteSnapshot,
    };
  }

  async earnings(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const drivers = await this.prisma.driver.findMany({
      where: { fleetOwnerId: fleet.id },
      select: { userId: true, id: true, user: { select: { name: true } } },
    });
    const userIds = drivers.map((row) => row.userId);
    const from = startOfTodayIst();
    const week = new Date(from.getTime() - 6 * 86400000);
    const entries = userIds.length
      ? await this.prisma.walletLedger.findMany({
          where: {
            kind: 'trip',
            direction: LedgerDirection.CREDIT,
            wallet: { ownerType: WalletOwnerType.DRIVER, ownerUserId: { in: userIds } },
          },
        })
      : [];
    const sum = (since: Date) =>
      entries
        .filter((row) => row.createdAt >= since)
        .reduce(
          (acc, row) => {
            acc.net += Number(row.amountPaise);
            acc.commission += Number(row.commissionPaise);
            acc.gross += Number(row.grossPaise);
            return acc;
          },
          { net: 0, commission: 0, gross: 0 },
        );
    const today = sum(from);
    const weekly = sum(week);
    const perDriver = await Promise.all(
      drivers.map(async (driver) => ({
        driverId: driver.id.toString(),
        name: driver.user.name,
        ...(await this.driverEarnings(driver.userId)),
      })),
    );
    return {
      today: money(today),
      week: money(weekly),
      drivers: perDriver,
    };
  }

  async wallet(actor: Actor) {
    await this.requireFleet(actor);
    await this.prisma.wallet.upsert({
      where: { ownerType_ownerUserId: { ownerType: WalletOwnerType.FLEET_OWNER, ownerUserId: actor.userId } },
      create: { ownerType: WalletOwnerType.FLEET_OWNER, ownerUserId: actor.userId, balancePaise: 0 },
      update: {},
    });
    return this.wallets.mine(actor.userId, actor.role);
  }

  async commission(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const rule = await this.prisma.commissionRule.findFirst({ where: { active: true, name: 'default' } });
    const totals = await this.earnings(actor);
    return {
      rule: serializeCommissionPolicy(rule),
      applied: totals.week,
      fleetId: fleet.id.toString(),
    };
  }

  async reports(actor: Actor) {
    const fleet = await this.requireFleet(actor);
    const from = startOfTodayIst();
    const month = startOfMonthIst();
    const where = { vehicle: { fleetOwnerId: fleet.id }, status: BookingStatus.COMPLETED };
    const [today, monthCount, vehicles, drivers, earnings] = await Promise.all([
      this.prisma.booking.count({ where: { ...where, updatedAt: { gte: from } } }),
      this.prisma.booking.count({ where: { ...where, updatedAt: { gte: month } } }),
      this.prisma.vehicle.groupBy({
        by: ['status'],
        where: { fleetOwnerId: fleet.id },
        _count: true,
      }),
      this.prisma.driver.count({ where: { fleetOwnerId: fleet.id } }),
      this.earnings(actor),
    ]);
    return {
      trips: { today, month: monthCount },
      vehicles: Object.fromEntries(
        vehicles.map((row) => [row.status, row._count]),
      ),
      drivers,
      earnings,
      statuses: FLEET_VEHICLE_STATUS_LABELS,
    };
  }

  private async presentVehicles(
    rows: Array<{
      id: bigint;
      districtId: number;
      category: string;
      registrationNo: string;
      status: string;
      brand: string | null;
      model: string | null;
      year: number | null;
      color: string | null;
      fuel: string | null;
      driverId: bigint | null;
      fleetOwnerId: bigint | null;
      lastLat: unknown;
      lastLng: unknown;
      lastFixAt: Date | null;
      driver?: {
        id: bigint;
        online: boolean;
        dutyStatus: string;
        user: { name: string; phone?: string | null };
      } | null;
      documents?: Array<{
        id: bigint;
        type: string;
        status: string;
        expiresAt: Date | null;
        rejectionReason: string | null;
        originalName: string | null;
      }>;
    }>,
  ) {
    const tripIds = rows.map((row) => row.driverId).filter((id): id is bigint => id != null);
    const live = tripIds.length
      ? await this.prisma.booking.findMany({
          where: { driverId: { in: tripIds }, status: { in: ACTIVE_RIDE_STATUSES } },
          select: { driverId: true },
        })
      : [];
    const onTrip = new Set(live.map((row) => row.driverId?.toString()));
    return rows.map((row) => {
      const resolved = resolveFleetVehicleStatus({
        stored: row.status,
        assignedDriverId: row.driverId,
        driverOnline: row.driver?.online,
        driverDuty: row.driver?.dutyStatus,
        hasActiveTrip: onTrip.has(row.driverId?.toString() ?? ''),
      });
      const docs = (row.documents ?? []).map((doc) => this.serializeVehicleDoc(doc));
      return {
        id: row.id.toString(),
        registrationNo: row.registrationNo,
        type: row.category,
        category: row.category,
        brand: row.brand,
        model: row.model,
        year: row.year,
        color: row.color,
        fuel: row.fuel,
        storedStatus: row.status,
        status: resolved.status,
        statusLabel: resolved.label,
        currentLocation: {
          lat: row.lastLat == null ? null : Number(row.lastLat),
          lng: row.lastLng == null ? null : Number(row.lastLng),
          at: row.lastFixAt?.toISOString() ?? null,
        },
        assignedDriver: row.driver
          ? {
              id: row.driver.id.toString(),
              name: row.driver.user.name,
              phone: row.driver.user.phone,
              dutyStatus: row.driver.dutyStatus,
            }
          : null,
        documents: docs,
        documentAlerts: docs.flatMap((doc) => alertsForDocument(doc)),
      };
    });
  }

  private serializeDriver(row: {
    id: bigint;
    userId: bigint;
    fleetOwnerId: bigint | null;
    online: boolean;
    dutyStatus: string;
    kycStatus: string;
    ratingAvg: unknown;
    licenseNo: string;
    city: string | null;
    user: { name: string; phone?: string | null; email?: string; status?: string };
    vehicles: Array<{ id: bigint; registrationNo: string; category: string; status: string }>;
    documents?: Array<{ type: string; status: string }>;
  }) {
    const assigned = row.vehicles[0] ?? null;
    return {
      id: row.id.toString(),
      userId: row.userId.toString(),
      name: row.user.name,
      phone: row.user.phone,
      email: row.user.email,
      profile: {
        city: row.city,
        licenseNo: row.licenseNo,
        kycStatus: row.kycStatus,
        ratingAvg: Number(row.ratingAvg),
      },
      status: row.dutyStatus,
      online: row.online,
      assignedVehicle: assigned
        ? {
            id: assigned.id.toString(),
            registrationNo: assigned.registrationNo,
            type: assigned.category,
            status: assigned.status,
          }
        : null,
      documentCount: row.documents?.length ?? 0,
    };
  }

  private serializeTrip(row: {
    id: bigint;
    publicRef?: string;
    status: string;
    pickupText: string;
    dropText: string;
    product?: string;
    quotePaise?: bigint | null;
    updatedAt: Date;
    driver?: { user: { name: string } } | null;
    vehicle?: { registrationNo: string } | null;
  }) {
    return {
      id: row.id.toString(),
      publicRef: row.publicRef ?? null,
      status: row.status,
      pickupText: row.pickupText,
      dropText: row.dropText,
      product: row.product ?? null,
      quoteRupees: Number(row.quotePaise ?? 0) / 100,
      driverName: row.driver?.user.name ?? null,
      registrationNo: row.vehicle?.registrationNo ?? null,
      updatedAt: row.updatedAt.toISOString(),
    };
  }

  private serializeVehicleDoc(row: {
    id: bigint;
    type: string;
    status: string;
    expiresAt?: Date | null;
    rejectionReason?: string | null;
    originalName?: string | null;
  }) {
    return {
      id: row.id.toString(),
      type: row.type,
      label: VEHICLE_DOC_LABELS[row.type as VehicleDocType] ?? row.type,
      status: row.status,
      expiresAt: row.expiresAt ? row.expiresAt.toISOString().slice(0, 10) : null,
      rejectionReason: row.rejectionReason ?? null,
      originalName: row.originalName ?? null,
    };
  }

  private async driverEarnings(userId: bigint) {
    const from = startOfTodayIst();
    const week = new Date(from.getTime() - 6 * 86400000);
    const entries = await this.prisma.walletLedger.findMany({
      where: {
        kind: 'trip',
        direction: LedgerDirection.CREDIT,
        createdAt: { gte: week },
        wallet: { ownerType: WalletOwnerType.DRIVER, ownerUserId: userId },
      },
    });
    const fold = (since: Date) =>
      entries
        .filter((row) => row.createdAt >= since)
        .reduce(
          (acc, row) => {
            acc.net += Number(row.amountPaise);
            acc.commission += Number(row.commissionPaise);
            acc.gross += Number(row.grossPaise);
            return acc;
          },
          { net: 0, commission: 0, gross: 0 },
        );
    return { today: money(fold(from)), week: money(fold(week)) };
  }

  private async requireFleet(actor: Actor) {
    if (actor.role !== UserRole.FLEET_OWNER || !actor.fleetOwnerId) {
      throw new ForbiddenException('Fleet owner profile required');
    }
    const fleet = await this.prisma.fleetOwner.findUnique({ where: { id: actor.fleetOwnerId } });
    if (!fleet || fleet.userId !== actor.userId) {
      throw new ForbiddenException('Access is limited to your own fleet');
    }
    return fleet;
  }

  private async requireVehicle(actor: Actor, vehicleId: bigint) {
    const fleet = await this.requireFleet(actor);
    const row = await this.prisma.vehicle.findUnique({
      where: { id: vehicleId },
      include: { driver: { include: { user: true } }, documents: true, district: { select: { stateId: true } } },
    });
    if (!row || row.fleetOwnerId !== fleet.id) {
      throw new NotFoundException('Vehicle not found');
    }
    this.scopes.assertVehicle(actor, {
      districtId: row.districtId,
      districtStateId: row.district.stateId,
      driverId: row.driverId,
      fleetOwnerId: row.fleetOwnerId,
    });
    return row;
  }

  private async requireFleetDriver(actor: Actor, driverId: bigint) {
    const fleet = await this.requireFleet(actor);
    const driver = await this.prisma.driver.findUnique({
      where: { id: driverId },
      include: {
        user: { select: { name: true, phone: true, email: true, status: true, districtId: true, stateId: true } },
        vehicles: true,
        documents: true,
      },
    });
    if (!driver || driver.fleetOwnerId !== fleet.id) {
      throw new NotFoundException('Driver not found');
    }
    this.scopes.assertDriver(actor, {
      id: driver.id,
      userId: driver.userId,
      fleetOwnerId: driver.fleetOwnerId,
      userDistrictId: driver.user.districtId,
      userStateId: driver.user.stateId,
      vehicleDistrictIds: driver.vehicles.map((row) => row.districtId),
      vehicleStateIds: [],
    });
    return driver;
  }

  private async ownerDistrict(userId: bigint) {
    const user = await this.prisma.user.findUnique({ where: { id: userId }, select: { districtId: true } });
    return user?.districtId ?? null;
  }

  private decodeFile(raw: string) {
    const cleaned = raw.includes(',') ? raw.slice(raw.indexOf(',') + 1) : raw;
    const buffer = Buffer.from(cleaned, 'base64');
    if (!buffer.length) {
      throw new BadRequestException('Document file is empty');
    }
    return buffer;
  }
}

function startOfTodayIst() {
  const day = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
  return new Date(`${day}T00:00:00+05:30`);
}

function startOfMonthIst() {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Kolkata',
    year: 'numeric',
    month: '2-digit',
  }).format(new Date());
  return new Date(`${parts}-01T00:00:00+05:30`);
}

function money(row: { net: number; commission: number; gross: number }) {
  return {
    grossPaise: row.gross,
    grossRupees: row.gross / 100,
    commissionPaise: row.commission,
    commissionRupees: row.commission / 100,
    netPaise: row.net,
    netRupees: row.net / 100,
  };
}
