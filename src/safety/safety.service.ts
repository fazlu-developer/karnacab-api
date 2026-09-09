import { randomBytes } from 'crypto';
import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Prisma, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { LocationService } from '../location/location.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { SupportService } from '../support/support.service';
import { KycStorage } from '../kyc/kyc.storage';
import { LIVE_STATUSES, toLifecycle } from '../ride-engine/booking-lifecycle';
import {
  EmergencyContactDto,
  ReviewIncidentDto,
  SafetyShareDto,
  SafetySosDto,
  SafetyTicketDto,
} from './dto/safety.dto';
import {
  canInvestigateIncidents,
  firstName,
  indianMobile,
  mapsUrl,
  phoneLast4,
  plateHint,
  SAFETY_INCIDENT_STATUSES,
  SAFETY_INCIDENT_TYPES,
} from './safety.privacy';

@Injectable()
export class SafetyService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly locations: LocationService,
    private readonly scopes: ScopeService,
    private readonly config: ConfigService,
    private readonly supportDesk: SupportService,
    private readonly kycFiles: KycStorage,
  ) {}

  catalog() {
    return {
      customer: [
        'sos',
        'emergency_contact',
        'live_trip_sharing',
        'driver_verification',
        'vehicle_verification',
        'start_otp',
        'end_otp',
        'complaint',
        'lost_found',
        'support',
      ],
      driver: ['sos', 'emergency_contact', 'support', 'trip_sharing'],
      incidentFields: ['user', 'booking', 'location', 'timestamp', 'emergencyContact', 'status'],
      types: SAFETY_INCIDENT_TYPES,
      statuses: SAFETY_INCIDENT_STATUSES,
      note: 'Share links and admin views use first names and last-4 identifiers only. OTPs never appear on share links.',
    };
  }

  async me(actor: Actor) {
    const [user, driver, police, helpline] = await Promise.all([
      this.prisma.user.findUnique({
        where: { id: actor.userId },
        select: { emergencyName: true, emergencyPhone: true, name: true },
      }),
      actor.driverId
        ? this.prisma.driver.findUnique({
            where: { id: actor.driverId },
            select: { emergencyName: true, emergencyPhone: true },
          })
        : Promise.resolve(null),
      this.setting('driver_safety_sos', '112'),
      this.setting('driver_safety_helpline', '08041234500'),
    ]);
    const emergencyName = driver?.emergencyName ?? user?.emergencyName ?? null;
    const emergencyPhone = driver?.emergencyPhone ?? user?.emergencyPhone ?? null;
    const contacts = await this.contacts(actor);
    return {
      sos: { phone: police, tel: `tel:${police.replace(/\D/g, '')}` },
      support: { phone: helpline, tel: `tel:${helpline.replace(/\D/g, '')}` },
      emergency: {
        name: emergencyName ?? contacts[0]?.name ?? null,
        phone: emergencyPhone ?? contacts[0]?.phone ?? null,
        tel: (emergencyPhone ?? contacts[0]?.phone)
          ? `tel:${(emergencyPhone ?? contacts[0]?.phone)!.replace(/\D/g, '')}`
          : null,
      },
      contacts,
      tools: actor.role === UserRole.DRIVER ? this.catalog().driver : this.catalog().customer,
    };
  }

  async contacts(actor: Actor) {
    const rows = await this.prisma.emergencyContact.findMany({
      where: { userId: actor.userId },
      orderBy: [{ isPrimary: 'desc' }, { id: 'desc' }],
    });
    if (rows.length) {
      return rows.map((row) => ({
        id: row.id.toString(),
        name: row.name,
        phone: row.phone,
        relation: row.relation,
        primary: row.isPrimary,
      }));
    }
    const profile = await this.prisma.user.findUnique({
      where: { id: actor.userId },
      select: { emergencyName: true, emergencyPhone: true },
    });
    if (!profile?.emergencyPhone) {
      return [];
    }
    return [
      {
        id: '0',
        name: profile.emergencyName,
        phone: profile.emergencyPhone,
        relation: null,
        primary: true,
      },
    ];
  }

  async saveEmergency(actor: Actor, dto: EmergencyContactDto) {
    const phone = indianMobile(dto.phone);
    if (!phone) {
      throw new BadRequestException('Enter a valid 10-digit Indian mobile number');
    }
    const name = dto.name.trim();
    if (actor.driverId) {
      await this.prisma.driver.update({
        where: { id: actor.driverId },
        data: { emergencyName: name, emergencyPhone: phone },
      });
    } else {
      await this.prisma.user.update({
        where: { id: actor.userId },
        data: { emergencyName: name, emergencyPhone: phone },
      });
    }
    await this.prisma.emergencyContact.updateMany({
      where: { userId: actor.userId },
      data: { isPrimary: false },
    });
    const existing = await this.prisma.emergencyContact.findFirst({
      where: { userId: actor.userId, phone },
    });
    if (existing) {
      await this.prisma.emergencyContact.update({
        where: { id: existing.id },
        data: { name, relation: dto.relation?.trim() || null, isPrimary: true },
      });
    } else {
      await this.prisma.emergencyContact.create({
        data: {
          userId: actor.userId,
          name,
          phone,
          relation: dto.relation?.trim() || null,
          isPrimary: true,
        },
      });
    }
    return this.me(actor);
  }

  async removeContact(actor: Actor, id: bigint) {
    const deleted = await this.prisma.emergencyContact.deleteMany({
      where: { id, userId: actor.userId },
    });
    if (!deleted.count) {
      throw new NotFoundException('Emergency contact not found');
    }
    return { ok: true };
  }

  async sos(actor: Actor, dto?: SafetySosDto) {
    const booking = await this.resolveBooking(actor, dto?.bookingId, true);
    const [profile, live, userFix, police, helpline, chat] = await Promise.all([
      this.me(actor),
      this.locations.mine(actor).catch(() => ({ lat: null as number | null, lng: null as number | null })),
      this.prisma.user.findUnique({
        where: { id: actor.userId },
        select: { lastLat: true, lastLng: true },
      }),
      this.setting('driver_safety_sos', '112'),
      this.setting('driver_safety_helpline', '08041234500'),
      this.setting('driver_support_chat_url', ''),
    ]);
    const lat =
      dto?.lat ??
      (Number((live as { lat?: number }).lat ?? 0) ||
        (userFix?.lastLat == null ? null : Number(userFix.lastLat)));
    const lng =
      dto?.lng ??
      (Number((live as { lng?: number }).lng ?? 0) ||
        (userFix?.lastLng == null ? null : Number(userFix.lastLng)));
    const map = mapsUrl(lat, lng);
    const share = booking ? await this.ensureShare(booking.id) : null;
    const shareText = this.shareSms(booking?.publicRef ?? null, map, share?.url ?? null);
    const payload = {
      police: { phone: police, tel: `tel:${police.replace(/\D/g, '')}` },
      karnacab: {
        phone: helpline,
        tel: `tel:${helpline.replace(/\D/g, '')}`,
        chatUrl: chat || null,
        chatAvailable: Boolean(chat),
      },
      emergency: profile.emergency,
      location: { lat, lng, mapsUrl: map },
      trip: {
        bookingId: booking?.id.toString() ?? null,
        publicRef: booking?.publicRef ?? null,
        shareUrl: share?.url ?? null,
        shareText,
        smsUrl: `sms:?body=${encodeURIComponent(shareText)}`,
        mapsUrl: map,
      },
      incident: null as Record<string, unknown> | null,
    };
    if (dto?.kind && dto.kind !== 'share') {
      payload.incident = await this.createIncident(actor, {
        type: 'sos',
        kind: dto.kind,
        description: dto.description?.trim() || `SOS ${dto.kind}`,
        bookingId: booking?.id ?? null,
        lat,
        lng,
      });
    }
    if (dto?.kind) {
      await this.prisma.platformAuditEvent.create({
        data: {
          actorUserId: actor.userId,
          domain: 'safety',
          action: 'sos',
          entityType: 'booking',
          entityId: booking?.id.toString() ?? null,
          payload: { kind: dto.kind, publicRef: booking?.publicRef ?? null, lat, lng } as Prisma.InputJsonValue,
        },
      });
    }
    return payload;
  }

  async share(actor: Actor, dto?: SafetyShareDto) {
    const booking = await this.resolveBooking(actor, dto?.bookingId, true);
    if (!booking) {
      throw new BadRequestException('Live trip sharing is available during an active ride');
    }
    const token = await this.ensureShare(booking.id);
    const map = mapsUrl(Number(booking.pickupLat), Number(booking.pickupLng));
    const shareText = this.shareSms(booking.publicRef, map, token.url);
    return {
      ...token,
      publicRef: booking.publicRef,
      shareText,
      smsUrl: `sms:?body=${encodeURIComponent(shareText)}`,
    };
  }

  async publicShare(token: string) {
    const booking = await this.prisma.booking.findFirst({
      where: { shareToken: token, shareExpiresAt: { gt: new Date() } },
      include: {
        customer: { select: { name: true } },
        driver: {
          select: {
            ratingAvg: true,
            kycStatus: true,
            user: { select: { name: true, lastLat: true, lastLng: true } },
            vehicles: { take: 1, select: { category: true, color: true, registrationNo: true, brand: true, model: true } },
          },
        },
        vehicle: { select: { category: true, color: true, registrationNo: true, brand: true, model: true } },
      },
    });
    if (!booking) {
      throw new NotFoundException('Share link expired or not found');
    }
    const vehicle = booking.vehicle ?? booking.driver?.vehicles[0] ?? null;
    const lat = booking.driver?.user.lastLat == null ? null : Number(booking.driver.user.lastLat);
    const lng = booking.driver?.user.lastLng == null ? null : Number(booking.driver.user.lastLng);
    return {
      publicRef: booking.publicRef,
      status: toLifecycle(booking.status),
      rider: { firstName: firstName(booking.customer.name) },
      driver: booking.driver
        ? {
            firstName: firstName(booking.driver.user.name),
            kycVerified: booking.driver.kycStatus === 'verified',
            rating: Number(booking.driver.ratingAvg),
          }
        : null,
      vehicle: vehicle
        ? {
            category: vehicle.category,
            color: vehicle.color,
            plateHint: plateHint(vehicle.registrationNo),
          }
        : null,
      lastLocation: { lat, lng, mapsUrl: mapsUrl(lat, lng) },
      expiresAt: booking.shareExpiresAt?.toISOString() ?? null,
    };
  }

  async verify(actor: Actor, bookingId: bigint) {
    const booking = await this.loadScopedBooking(actor, bookingId);
    const isCustomer = booking.customerId === actor.userId;
    const isDriver = actor.driverId != null && booking.driverId === actor.driverId;
    const vehicle = booking.vehicle;
    const driver = booking.driver;
    const lifecycle = toLifecycle(booking.status);
    const photo = driver
      ? await this.prisma.driverDocument.findFirst({
          where: { driverId: driver.id, type: { in: ['PHOTO', 'SELFIE'] } },
          orderBy: { id: 'desc' },
        })
      : null;
    const origin = this.config.get<string>('app.url') ?? 'http://localhost:3000';
    const prefix = this.config.get<string>('app.prefix') ?? 'api';
    const version = this.config.get<string>('app.version') ?? '1';
    return {
      publicRef: booking.publicRef,
      status: lifecycle,
      driver: driver
        ? {
            name: isCustomer ? driver.user.name : firstName(driver.user.name),
            firstName: firstName(driver.user.name),
            photoUrl:
              photo && (isCustomer || isDriver)
                ? `${origin.replace(/\/$/, '')}/${prefix}/v${version}/safety/bookings/${booking.id.toString()}/driver-photo`
                : null,
            kycVerified: driver.kycStatus === 'verified',
            verificationStatus: driver.kycStatus,
            rating: Number(driver.ratingAvg),
            phone: isCustomer ? driver.user.phone : null,
          }
        : null,
      vehicle: vehicle
        ? {
            number: isCustomer || isDriver ? vehicle.registrationNo : plateHint(vehicle.registrationNo),
            type: vehicle.category,
            category: vehicle.category,
            color: vehicle.color,
            brand: vehicle.brand,
            model: vehicle.model,
            registrationNo: isCustomer || isDriver ? vehicle.registrationNo : plateHint(vehicle.registrationNo),
          }
        : null,
      otp: isCustomer
        ? {
            start: booking.startOtp,
            end: lifecycle === 'started' || lifecycle === 'completed' ? booking.endOtp : null,
          }
        : { start: null, end: null },
      share: isCustomer || isDriver ? await this.ensureShare(booking.id) : null,
    };
  }

  async driverPhoto(actor: Actor, bookingId: bigint) {
    const view = await this.verify(actor, bookingId);
    if (!view.driver) {
      throw new NotFoundException('Driver photo is not available');
    }
    const booking = await this.loadScopedBooking(actor, bookingId);
    if (!booking.driverId) {
      throw new NotFoundException('Driver photo is not available');
    }
    const photo = await this.prisma.driverDocument.findFirst({
      where: { driverId: booking.driverId, type: { in: ['PHOTO', 'SELFIE'] } },
      orderBy: { id: 'desc' },
    });
    if (!photo) {
      throw new NotFoundException('Driver photo is not available');
    }
    const buffer = await this.kycFiles.read(photo.storageKey);
    return { mime: photo.mime, name: photo.originalName || 'driver-photo', buffer };
  }

  async createTicket(actor: Actor, dto: SafetyTicketDto) {
    const booking = dto.bookingId ? await this.loadScopedBooking(actor, BigInt(dto.bookingId)) : null;
    const incident = await this.createIncident(actor, {
      type: dto.type,
      description: dto.description.trim(),
      bookingId: booking?.id ?? null,
      lat: null,
      lng: null,
    });
    await this.supportDesk.openFromSafety(actor, {
      incidentId: BigInt(incident.id),
      bookingId: booking?.id ?? null,
      description: dto.description.trim(),
      districtId: actor.districtId ?? booking?.districtId ?? null,
    });
    return { ...incident, ticketLinked: true };
  }

  async listIncidents(actor: Actor) {
    const where = this.incidentWhere(actor);
    const rows = await this.prisma.safetyIncident.findMany({
      where,
      orderBy: { createdAt: 'desc' },
      take: 100,
      include: this.incidentInclude(),
    });
    return { incidents: rows.map((row) => this.presentIncident(row, actor)) };
  }

  async oneIncident(actor: Actor, id: bigint) {
    const row = await this.prisma.safetyIncident.findUnique({
      where: { id },
      include: this.incidentInclude(),
    });
    if (!row) {
      throw new NotFoundException('Incident not found');
    }
    this.assertIncident(actor, row);
    return this.presentIncident(row, actor);
  }

  async review(actor: Actor, id: bigint, dto: ReviewIncidentDto) {
    if (!canInvestigateIncidents(actor.role, actor.unrestricted)) {
      throw new ForbiddenException('Support access required');
    }
    await this.oneIncident(actor, id);
    const row = await this.prisma.safetyIncident.update({
      where: { id },
      data: {
        status: dto.status,
        adminNote: dto.adminNote?.trim() || undefined,
      },
      include: this.incidentInclude(),
    });
    return this.presentIncident(row, actor);
  }

  private incidentWhere(actor: Actor): Prisma.SafetyIncidentWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { district: { stateId: actor.stateId } };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { districtId: actor.districtId };
    }
    return {
      OR: [{ reporterUserId: actor.userId }, ...(actor.driverId ? [{ driverId: actor.driverId }] : [])],
    };
  }

  private assertIncident(
    actor: Actor,
    row: {
      reporterUserId: bigint;
      driverId: bigint | null;
      districtId: number | null;
      district?: { stateId: number } | null;
    },
  ) {
    if (actor.unrestricted) {
      return;
    }
    if (row.reporterUserId === actor.userId) {
      return;
    }
    if (actor.driverId && row.driverId === actor.driverId) {
      return;
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null && row.district?.stateId === actor.stateId) {
      return;
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null &&
      row.districtId === actor.districtId
    ) {
      return;
    }
    throw new ForbiddenException('Access denied for this incident');
  }

  private async createIncident(
    actor: Actor,
    input: {
      type: string;
      kind?: string;
      description: string;
      bookingId: bigint | null;
      lat: number | null;
      lng: number | null;
    },
  ) {
    const booking = input.bookingId
      ? await this.prisma.booking.findUnique({
          where: { id: input.bookingId },
          select: { id: true, driverId: true, districtId: true, pickupText: true },
        })
      : null;
    const user = await this.prisma.user.findUnique({
      where: { id: actor.userId },
      select: { lastAddress: true, lastLat: true, lastLng: true, emergencyName: true, emergencyPhone: true },
    });
    const lat = input.lat ?? (user?.lastLat == null ? null : Number(user.lastLat));
    const lng = input.lng ?? (user?.lastLng == null ? null : Number(user.lastLng));
    const row = await this.prisma.safetyIncident.create({
      data: {
        publicRef: this.newRef('KCS'),
        type: input.type,
        status: 'open',
        description: input.description.slice(0, 1000),
        bookingId: booking?.id ?? null,
        reporterUserId: actor.userId,
        driverId: actor.driverId ?? booking?.driverId ?? null,
        districtId: actor.districtId ?? booking?.districtId ?? null,
        lat,
        lng,
        locationText: booking?.pickupText ?? user?.lastAddress ?? null,
        kind: input.kind ?? null,
        actorRole: actor.role,
        emergencyName: user?.emergencyName ?? null,
        emergencyPhone: user?.emergencyPhone ?? null,
      },
      include: this.incidentInclude(),
    });
    return this.presentIncident(row, actor);
  }

  private presentIncident(
    row: {
      id: bigint;
      publicRef: string;
      type: string;
      status: string;
      description: string;
      bookingId: bigint | null;
      reporterUserId: bigint;
      driverId: bigint | null;
      lat: unknown;
      lng: unknown;
      locationText: string | null;
      adminNote: string | null;
      emergencyName?: string | null;
      emergencyPhone?: string | null;
      createdAt: Date;
      booking?: { publicRef: string } | null;
      reporter?: { name: string; phone: string | null };
      driver?: { user: { name: string; phone: string | null } } | null;
    },
    actor: Actor,
  ) {
    const investigator = canInvestigateIncidents(actor.role, actor.unrestricted);
    return {
      id: row.id.toString(),
      incidentId: row.publicRef,
      bookingId: row.bookingId?.toString() ?? null,
      bookingRef: row.booking?.publicRef ?? null,
      user: {
        firstName: firstName(row.reporter?.name),
        phoneLast4: phoneLast4(row.reporter?.phone),
      },
      driver: row.driver
        ? {
            firstName: firstName(row.driver.user.name),
            phoneLast4: phoneLast4(row.driver.user.phone),
          }
        : null,
      location: {
        lat: row.lat == null ? null : Number(row.lat),
        lng: row.lng == null ? null : Number(row.lng),
        text: row.locationText,
        mapsUrl: mapsUrl(row.lat == null ? null : Number(row.lat), row.lng == null ? null : Number(row.lng)),
      },
      timestamp: row.createdAt.toISOString(),
      emergencyContact: {
        name: row.emergencyName ?? null,
        phone: investigator ? row.emergencyPhone ?? null : phoneLast4(row.emergencyPhone),
      },
      type: row.type,
      description: row.description,
      status: row.status,
      adminNote: investigator ? row.adminNote : null,
    };
  }

  private incidentInclude() {
    return {
      booking: { select: { publicRef: true } },
      reporter: { select: { name: true, phone: true } },
      driver: { select: { user: { select: { name: true, phone: true } } } },
      district: { select: { stateId: true } },
    } as const;
  }

  private async resolveBooking(actor: Actor, bookingId?: string, liveOnly = false) {
    if (bookingId) {
      const row = await this.loadScopedBooking(actor, BigInt(bookingId));
      if (liveOnly && !LIVE_STATUSES.includes(row.status)) {
        return null;
      }
      return row;
    }
    return this.prisma.booking.findFirst({
      where: {
        status: liveOnly ? { in: LIVE_STATUSES } : undefined,
        OR: [{ customerId: actor.userId }, ...(actor.driverId ? [{ driverId: actor.driverId }] : [])],
      },
      orderBy: { updatedAt: 'desc' },
      include: this.bookingSafetyInclude(),
    });
  }

  private async loadScopedBooking(actor: Actor, id: bigint) {
    const booking = await this.prisma.booking.findUnique({
      where: { id },
      include: this.bookingSafetyInclude(),
    });
    if (!booking) {
      throw new NotFoundException('Booking not found');
    }
    this.scopes.assertBooking(actor, {
      customerId: booking.customerId,
      driverId: booking.driverId,
      vehicleFleetOwnerId: booking.vehicle?.fleetOwnerId ?? null,
      districtId: booking.districtId,
      districtStateId: booking.district?.stateId ?? null,
      status: booking.status,
    });
    return booking;
  }

  private bookingSafetyInclude() {
    return {
      district: { select: { stateId: true } },
      vehicle: {
        select: {
          fleetOwnerId: true,
          category: true,
          color: true,
          brand: true,
          model: true,
          registrationNo: true,
        },
      },
      driver: {
        select: {
          id: true,
          kycStatus: true,
          ratingAvg: true,
          user: { select: { name: true, phone: true, lastLat: true, lastLng: true } },
        },
      },
      customer: { select: { name: true } },
    } as const;
  }

  private async ensureShare(bookingId: bigint) {
    const existing = await this.prisma.booking.findUnique({
      where: { id: bookingId },
      select: { shareToken: true, shareExpiresAt: true },
    });
    const fresh =
      existing?.shareToken && existing.shareExpiresAt && existing.shareExpiresAt > new Date()
        ? existing
        : await this.prisma.booking.update({
            where: { id: bookingId },
            data: {
              shareToken: randomBytes(18).toString('base64url'),
              shareExpiresAt: new Date(Date.now() + 48 * 60 * 60 * 1000),
            },
            select: { shareToken: true, shareExpiresAt: true },
          });
    const origin = this.config.get<string>('app.url') ?? 'http://localhost:3000';
    const prefix = this.config.get<string>('app.prefix') ?? 'api';
    const version = this.config.get<string>('app.version') ?? '1';
    return {
      token: fresh.shareToken,
      url: `${origin.replace(/\/$/, '')}/${prefix}/v${version}/safety/share/${fresh.shareToken}`,
      expiresAt: fresh.shareExpiresAt?.toISOString() ?? null,
    };
  }

  private shareSms(publicRef: string | null, map: string | null, shareUrl: string | null) {
    const parts = ['KarnaCab live trip'];
    if (publicRef) {
      parts.push(`ref ${publicRef}`);
    }
    if (shareUrl) {
      parts.push(shareUrl);
    } else if (map) {
      parts.push(map);
    }
    return parts.join('. ');
  }

  private newRef(prefix: string) {
    return `${prefix}${randomBytes(6).toString('hex').toUpperCase()}`.slice(0, 24);
  }

  private async setting(key: string, fallback: string) {
    const row = await this.prisma.systemSetting.findUnique({ where: { key } });
    return row?.value?.trim() || fallback;
  }
}
