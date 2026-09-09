import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { randomInt } from 'crypto';
import { Prisma, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { GoogleMapsService } from '../places/google-maps.service';
import { DomainEvents } from '../common/domain-events.service';
import { ScopeService } from '../access/scope.service';
import { PaymentsService } from '../payments/payments.service';
import { Actor } from '../access/territory';
import { ParcelFareEngine } from './parcel-fare.engine';
import { ParcelPolicy } from './parcel-policy';
import { presentParcel } from './parcel.presenter';
import { CreateParcelDto, ParcelLifecycleDto, ParcelQuoteDto, PayParcelDto } from './dto/parcel.dto';
import { ParcelAction, nextParcelStatus, normalizeParcelStatus } from './parcel-lifecycle';
import { persistFromDuty } from '../drivers/driver-duty';
import { driverCanReceiveOffers } from '../drivers/driver-offer-gate';
import {
  driverHasActiveJob,
  loadDriverOfferProfile,
  lockDriverRow,
  lockParcelRow,
  recordDecline,
} from '../drivers/driver-offer-profile';
import { presentParcelOffer } from '../drivers/driver-request.presenter';
import { parcelServiceAllowed, vehicleMatches, withinOfferRadius } from '../drivers/offer-eligibility';

@Injectable()
export class ParcelsService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly fares: ParcelFareEngine,
    private readonly policy: ParcelPolicy,
    private readonly maps: GoogleMapsService,
    private readonly events: DomainEvents,
    private readonly scopes: ScopeService,
    private readonly payments: PaymentsService,
  ) {}

  catalog() {
    return this.policy.catalog();
  }

  async quote(dto: ParcelQuoteDto) {
    const distanceKm = await this.resolveKm(dto);
    return this.fares.quote({ ...dto, distanceKm });
  }

  async create(actor: Actor, dto: CreateParcelDto) {
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE) {
      throw new ForbiddenException('Only customers can create a parcel');
    }
    if (!dto.complianceConfirmed) {
      throw new BadRequestException('Confirm the parcel does not contain prohibited goods');
    }
    if (await this.policy.isProhibited(dto.parcelType)) {
      throw new BadRequestException('That item is prohibited and cannot be shipped');
    }
    if (!(await this.policy.isAllowedType(dto.parcelType))) {
      throw new BadRequestException('Choose a permitted parcel type');
    }
    const distanceKm = await this.resolveKm(dto);
    const quote = await this.fares.quote({ ...dto, distanceKm });
    const row = await this.prisma.parcelShipment.create({
      data: {
        publicRef: `KP${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        customerId: actor.userId,
        biharLane: dto.biharLane,
        parcelType: dto.parcelType,
        description: dto.description,
        weightKg: dto.weightKg,
        lengthCm: dto.lengthCm,
        widthCm: dto.widthCm,
        heightCm: dto.heightCm,
        quantity: dto.quantity ?? 1,
        category: dto.category,
        pickupText: dto.pickupText,
        dropText: dto.dropText,
        pickupLat: dto.pickupLat,
        pickupLng: dto.pickupLng,
        dropLat: dto.dropLat,
        dropLng: dto.dropLng,
        distanceKm,
        contactName: dto.contactName,
        contactPhone: dto.contactPhone,
        instructions: dto.instructions,
        complianceConfirmed: true,
        quotePaise: BigInt(quote.totalPaise),
        quoteSnapshot: quote as Prisma.InputJsonValue,
        pickupOtp: String(randomInt(1000, 9999)),
        deliveryPin: String(randomInt(1000, 9999)),
        status: 'created',
        paymentStatus: 'unpaid',
      },
    });
    this.events.emit('parcel.created', {
      parcelId: row.id.toString(),
      customerId: actor.userId.toString(),
    });
    return presentParcel(row, { quote, role: actor.role });
  }

  async list(actor: Actor) {
    const rows = await this.prisma.parcelShipment.findMany({
      where: this.scopes.parcelWhere(actor),
      include: { driver: { include: { user: { select: { name: true } } } } },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    return {
      parcels: rows.map((row) =>
        presentParcel(row, { driverName: row.driver?.user.name, role: actor.role }),
      ),
    };
  }

  async one(actor: Actor, id: bigint) {
    const row = await this.load(actor, id);
    return presentParcel(row, {
      driverName: row.driver?.user.name,
      role: actor.role,
    });
  }

  async pay(actor: Actor, id: bigint, dto: PayParcelDto) {
    const row = await this.load(actor, id);
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE) {
      throw new ForbiddenException('Only the customer can pay');
    }
    if (row.paymentStatus === 'paid') {
      return presentParcel(row, { role: actor.role });
    }
    if (normalizeParcelStatus(row.status) !== 'created') {
      throw new BadRequestException('This parcel can no longer be paid');
    }
    await this.payments.initiate(actor, {
      method: dto.method || 'cash',
      parcelId: row.id,
      amountPaise: Number(row.quotePaise ?? 0),
    });
    const updated = await this.prisma.parcelShipment.findUniqueOrThrow({
      where: { id: row.id },
      include: { driver: { include: { user: { select: { name: true } } } } },
    });
    this.events.emit('parcel.lifecycle', {
      parcelId: id.toString(),
      action: 'pay',
      status: updated.status,
    });
    return presentParcel(updated, { driverName: updated.driver?.user.name, role: actor.role });
  }

  async offersForDriver(actor: Actor) {
    if (actor.role !== UserRole.DRIVER) {
      throw new ForbiddenException('Driver role required');
    }
    const profile = await loadDriverOfferProfile(this.prisma, actor.userId);
    if (!profile?.parcelEnabled || !(await driverCanReceiveOffers(this.prisma, actor.userId))) {
      return { offers: [] };
    }
    const since = new Date(Date.now() - 15 * 60 * 1000);
    const catalog = await this.policy.catalog();
    const allowedTypes = catalog.types.map((row) => row.key);
    const prohibitedTypes = catalog.prohibited.map((row) => row.key);
    const rows = await this.prisma.parcelShipment.findMany({
      where: {
        status: 'created',
        paymentStatus: { in: ['paid', 'cod'] },
        complianceConfirmed: true,
        driverId: null,
        createdAt: { gte: since },
        ...(profile.declinedParcelIds.length
          ? { id: { notIn: profile.declinedParcelIds } }
          : {}),
      },
      orderBy: { createdAt: 'desc' },
      take: 40,
    });
    return {
      offers: rows
        .filter((row) =>
          this.parcelEligible(profile, row, allowedTypes, prohibitedTypes),
        )
        .slice(0, 20)
        .map((row) => presentParcelOffer(presentParcel(row, { role: UserRole.DRIVER }))),
    };
  }

  async reject(actor: Actor, id: bigint) {
    if (actor.role !== UserRole.DRIVER) {
      throw new ForbiddenException('Driver role required');
    }
    const driver = await this.prisma.driver.findUnique({ where: { userId: actor.userId } });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }
    const row = await this.prisma.parcelShipment.findUnique({ where: { id } });
    if (!row) {
      throw new NotFoundException('Parcel not found');
    }
    if (row.driverId == null) {
      await recordDecline(this.prisma, driver.id, 'parcel', id);
    }
    return { declined: true, id: id.toString() };
  }

  async accept(actor: Actor, id: bigint) {
    if (actor.role !== UserRole.DRIVER || !actor.driverId) {
      throw new ForbiddenException('Driver role required');
    }
    const profile = await loadDriverOfferProfile(this.prisma, actor.userId);
    if (!profile?.parcelEnabled || !(await driverCanReceiveOffers(this.prisma, actor.userId))) {
      throw new ForbiddenException('Not available for delivery requests');
    }
    const catalog = await this.policy.catalog();
    const allowedTypes = catalog.types.map((row) => row.key);
    const prohibitedTypes = catalog.prohibited.map((row) => row.key);

    await this.prisma.$transaction(
      async (tx) => {
        await lockDriverRow(tx, profile.driverId);
        if (await driverHasActiveJob(tx, profile.driverId)) {
          throw new ConflictException('Finish your current job before accepting another');
        }
        const locked = await lockParcelRow(tx, id);
        if (!locked) {
          throw new NotFoundException('Parcel not found');
        }
        if (locked.driver_id != null) {
          throw new ConflictException('This parcel was taken by another driver');
        }
        const row = await tx.parcelShipment.findUnique({ where: { id } });
        if (!row || !this.parcelEligible(profile, row, allowedTypes, prohibitedTypes)) {
          throw new ForbiddenException('You are not eligible for this parcel');
        }
        const vehicleId =
          profile.vehicleIdsByCategory.get(String(row.category)) ??
          (profile.categories.length ? null : profile.firstVehicleId);
        const updated = await tx.parcelShipment.updateMany({
          where: {
            id,
            status: 'created',
            paymentStatus: { in: ['paid', 'cod'] },
            complianceConfirmed: true,
            driverId: null,
          },
          data: {
            status: 'assigned',
            driverId: actor.driverId,
            vehicleId: vehicleId ?? undefined,
          },
        });
        if (updated.count === 0) {
          throw new ConflictException('This parcel was taken by another driver');
        }
        await tx.driver.update({
          where: { id: profile.driverId },
          data: persistFromDuty('on_delivery'),
        });
      },
      { isolationLevel: Prisma.TransactionIsolationLevel.ReadCommitted },
    );

    const assigned = await this.one(actor, id);
    return presentParcelOffer(assigned as Record<string, unknown>, { assigned: true, role: actor.role });
  }

  private parcelEligible(
    profile: NonNullable<Awaited<ReturnType<typeof loadDriverOfferProfile>>>,
    row: {
      status: string;
      paymentStatus: string;
      complianceConfirmed: boolean;
      parcelType: string;
      category: string;
      pickupLat?: unknown;
      pickupLng?: unknown;
    },
    allowedTypes: string[],
    prohibitedTypes: string[],
  ) {
    return (
      parcelServiceAllowed({
        parcelEnabled: profile.parcelEnabled,
        status: row.status,
        paymentStatus: row.paymentStatus,
        complianceConfirmed: row.complianceConfirmed,
        parcelType: row.parcelType,
        allowedTypes,
        prohibitedTypes,
      }) &&
      vehicleMatches(profile.categories, String(row.category)) &&
      withinOfferRadius({
        driverLat: profile.lat,
        driverLng: profile.lng,
        pickupLat: row.pickupLat == null ? null : Number(row.pickupLat),
        pickupLng: row.pickupLng == null ? null : Number(row.pickupLng),
        radiusKm: profile.radiusKm,
      })
    );
  }

  async transition(actor: Actor, id: bigint, dto: ParcelLifecycleDto) {
    const row = await this.load(actor, id);
    const action = dto.action as ParcelAction;
    let next: ReturnType<typeof nextParcelStatus>;
    try {
      next = nextParcelStatus(row.status, action);
    } catch (err) {
      throw new BadRequestException(err instanceof Error ? err.message : 'Invalid transition');
    }
    if (action === 'pickup') {
      if (actor.role !== UserRole.DRIVER) {
        throw new ForbiddenException('Driver must confirm pickup');
      }
      if (row.pickupOtp && dto.otp !== row.pickupOtp) {
        throw new ForbiddenException('Invalid pickup OTP');
      }
    }
    if (action === 'deliver') {
      if (actor.role !== UserRole.DRIVER) {
        throw new ForbiddenException('Driver must confirm delivery');
      }
      if (row.deliveryPin && dto.otp !== row.deliveryPin) {
        throw new ForbiddenException('Invalid delivery OTP');
      }
    }
    if (actor.role === UserRole.DRIVER && row.driverId !== actor.driverId) {
      throw new ForbiddenException('This parcel is assigned to another driver');
    }
    const updated = await this.prisma.parcelShipment.update({
      where: { id },
      data: { status: next },
      include: { driver: { include: { user: { select: { name: true } } } } },
    });
    if (updated.driverId && (next === 'delivered' || next === 'cancelled')) {
      await this.prisma.driver.update({
        where: { id: updated.driverId },
        data: persistFromDuty('online'),
      });
    } else if (updated.driverId) {
      await this.prisma.driver.update({
        where: { id: updated.driverId },
        data: persistFromDuty('on_delivery'),
      });
    }
    this.events.emit('parcel.lifecycle', {
      parcelId: id.toString(),
      action,
      status: next,
    });
    return presentParcel(updated, { driverName: updated.driver?.user.name, role: actor.role });
  }

  private async resolveKm(dto: {
    distanceKm?: number;
    pickupLat?: number;
    pickupLng?: number;
    dropLat?: number;
    dropLng?: number;
  }) {
    if (dto.pickupLat != null && dto.pickupLng != null && dto.dropLat != null && dto.dropLng != null) {
      try {
        const route = await this.maps.directions(dto.pickupLat, dto.pickupLng, dto.dropLat, dto.dropLng);
        return Math.max(1, Math.round(route.distanceKm * 10) / 10);
      } catch {
        return Math.max(1, Math.round(this.maps.haversineKm(dto.pickupLat, dto.pickupLng, dto.dropLat, dto.dropLng)));
      }
    }
    return Math.max(1, dto.distanceKm ?? 1);
  }

  private async load(actor: Actor, id: bigint) {
    const row = await this.prisma.parcelShipment.findUnique({
      where: { id },
      include: {
        customer: { select: { districtId: true, stateId: true } },
        driver: { include: { user: { select: { name: true } } } },
      },
    });
    if (!row) {
      throw new NotFoundException('Parcel not found');
    }
    this.scopes.assertParcel(actor, {
      customerId: row.customerId,
      customerDistrictId: row.customer.districtId,
      customerStateId: row.customer.stateId,
      driverId: row.driverId,
    });
    return row;
  }
}
