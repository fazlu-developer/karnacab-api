import {
  BadRequestException,
  ForbiddenException,
  Injectable,
  NotFoundException,
} from '@nestjs/common';
import { randomInt } from 'crypto';
import { PackageStatus, Prisma, UserRole } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { DomainEvents } from '../common/domain-events.service';
import { ScopeService } from '../access/scope.service';
import { PaymentsService } from '../payments/payments.service';
import { Actor } from '../access/territory';
import {
  dateAllowed,
  parseCategoryList,
} from './travel-categories';
import { presentTravelBooking, presentTravelPackage } from './travel.presenter';
import {
  BookTravelDto,
  PatchTravelPackageDto,
  PayTravelDto,
  TravelPackageQueryDto,
  UpsertTravelPackageDto,
} from './dto/travel.dto';

@Injectable()
export class TravelService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly events: DomainEvents,
    private readonly scopes: ScopeService,
    private readonly payments: PaymentsService,
  ) {}

  async categories() {
    const row = await this.prisma.systemSetting.findUnique({ where: { key: 'travel_categories' } });
    return parseCategoryList(row?.value);
  }

  async catalog() {
    const categories = await this.categories();
    return {
      categories,
      tracking: ['Created', 'Confirmed'],
    };
  }

  async listPublished(query: TravelPackageQueryDto) {
    const categories = await this.categories();
    const allowed = new Set(categories.map((row) => row.key));
    const where: Prisma.TravelPackageWhereInput = { status: PackageStatus.PUBLISHED };
    if (query.category) {
      if (!allowed.has(query.category)) {
        throw new BadRequestException('Unknown travel category');
      }
      where.category = query.category;
    }
    if (query.destination?.trim()) {
      where.destination = { contains: query.destination.trim() };
    }
    const rows = await this.prisma.travelPackage.findMany({
      where,
      orderBy: [{ category: 'asc' }, { id: 'asc' }],
      take: 100,
    });
    const date = query.date?.trim();
    return {
      packages: rows
        .filter((row) => !date || dateAllowed(row.availableDates, date))
        .map((row) => presentTravelPackage(row, categories)),
    };
  }

  async onePublished(id: number) {
    const categories = await this.categories();
    const row = await this.prisma.travelPackage.findFirst({
      where: { id, status: PackageStatus.PUBLISHED },
    });
    if (!row) {
      throw new NotFoundException('Package not found');
    }
    return presentTravelPackage(row, categories);
  }

  async listAdmin(actor: Actor) {
    this.assertTravelRead(actor);
    const categories = await this.categories();
    const where: Prisma.TravelPackageWhereInput = {};
    if (!actor.unrestricted && actor.districtId != null) {
      where.districtId = actor.districtId;
    }
    const rows = await this.prisma.travelPackage.findMany({
      where,
      orderBy: { updatedAt: 'desc' },
      take: 200,
    });
    return { packages: rows.map((row) => presentTravelPackage(row, categories)) };
  }

  async createPackage(actor: Actor, dto: UpsertTravelPackageDto) {
    this.assertTravelWrite(actor);
    await this.assertCategory(dto.category);
    const row = await this.prisma.travelPackage.create({
      data: {
        districtId: dto.districtId,
        category: dto.category,
        title: dto.title,
        destination: dto.destination,
        places: dto.places,
        durationHours: dto.durationHours,
        durationLabel: dto.durationLabel,
        kmIncluded: dto.kmIncluded,
        vehicleLabel: dto.vehicleLabel,
        driverLabel: dto.driverLabel ?? 'Dedicated driver',
        pricePaise: dto.pricePaise,
        inclusions: dto.inclusions,
        exclusions: dto.exclusions,
        itinerary: dto.itinerary as unknown as Prisma.InputJsonValue,
        gallery: dto.gallery as unknown as Prisma.InputJsonValue,
        availableDates: dto.availableDates as unknown as Prisma.InputJsonValue,
        status: dto.status ?? PackageStatus.DRAFT,
      },
    });
    return presentTravelPackage(row, await this.categories());
  }

  async updatePackage(actor: Actor, id: number, dto: PatchTravelPackageDto) {
    this.assertTravelWrite(actor);
    if (dto.category) {
      await this.assertCategory(dto.category);
    }
    const existing = await this.prisma.travelPackage.findUnique({ where: { id } });
    if (!existing) {
      throw new NotFoundException('Package not found');
    }
    const row = await this.prisma.travelPackage.update({
      where: { id },
      data: {
        districtId: dto.districtId,
        category: dto.category,
        title: dto.title,
        destination: dto.destination,
        places: dto.places,
        durationHours: dto.durationHours,
        durationLabel: dto.durationLabel,
        kmIncluded: dto.kmIncluded,
        vehicleLabel: dto.vehicleLabel,
        driverLabel: dto.driverLabel,
        pricePaise: dto.pricePaise,
        inclusions: dto.inclusions,
        exclusions: dto.exclusions,
        itinerary: dto.itinerary as unknown as Prisma.InputJsonValue | undefined,
        gallery: dto.gallery as unknown as Prisma.InputJsonValue | undefined,
        availableDates: dto.availableDates as unknown as Prisma.InputJsonValue | undefined,
        status: dto.status,
      },
    });
    return presentTravelPackage(row, await this.categories());
  }

  async book(actor: Actor, dto: BookTravelDto) {
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE) {
      throw new ForbiddenException('Only customers can book travel');
    }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dto.travelDate)) {
      throw new BadRequestException('travelDate must be YYYY-MM-DD');
    }
    const pkg = await this.prisma.travelPackage.findFirst({
      where: { id: dto.packageId, status: PackageStatus.PUBLISHED },
    });
    if (!pkg) {
      throw new NotFoundException('Package is not available');
    }
    if (!dateAllowed(pkg.availableDates, dto.travelDate)) {
      throw new BadRequestException('That date is not available for this package');
    }
    const categories = await this.categories();
    const snapshot = presentTravelPackage(pkg, categories);
    const row = await this.prisma.travelBooking.create({
      data: {
        publicRef: `KT${Date.now().toString(36)}${randomInt(100, 999)}`.toUpperCase(),
        customerId: actor.userId,
        packageId: pkg.id,
        travelDate: new Date(`${dto.travelDate}T00:00:00.000Z`),
        guests: dto.guests ?? 1,
        contactName: dto.contactName,
        contactPhone: dto.contactPhone,
        notes: dto.notes,
        quotePaise: BigInt(pkg.pricePaise),
        quoteSnapshot: snapshot as Prisma.InputJsonValue,
        status: 'created',
        paymentStatus: 'unpaid',
      },
      include: { package: true },
    });
    this.events.emit('travel.created', {
      bookingId: row.id.toString(),
      customerId: actor.userId.toString(),
    });
    return presentTravelBooking(row, categories);
  }

  async listBookings(actor: Actor) {
    const categories = await this.categories();
    const rows = await this.prisma.travelBooking.findMany({
      where: this.scopes.travelBookingWhere(actor),
      include: { package: true },
      orderBy: { createdAt: 'desc' },
      take: 50,
    });
    return { bookings: rows.map((row) => presentTravelBooking(row, categories)) };
  }

  async oneBooking(actor: Actor, id: bigint) {
    return presentTravelBooking(await this.loadBooking(actor, id), await this.categories());
  }

  async pay(actor: Actor, id: bigint, dto: PayTravelDto) {
    const row = await this.loadBooking(actor, id);
    if (actor.role !== UserRole.CUSTOMER && actor.role !== UserRole.CORPORATE) {
      throw new ForbiddenException('Only the customer can pay');
    }
    if (row.paymentStatus === 'paid') {
      return presentTravelBooking(row, await this.categories());
    }
    if (row.status !== 'created') {
      throw new BadRequestException('This booking can no longer be paid');
    }
    await this.payments.initiate(actor, {
      method: dto.method || 'cash',
      travelBookingId: row.id,
      amountPaise: Number(row.quotePaise),
    });
    const updated = await this.prisma.travelBooking.findUniqueOrThrow({
      where: { id: row.id },
      include: { package: true },
    });
    this.events.emit('travel.lifecycle', {
      bookingId: id.toString(),
      action: 'pay',
      status: updated.status,
    });
    return presentTravelBooking(updated, await this.categories());
  }

  private async loadBooking(actor: Actor, id: bigint) {
    const row = await this.prisma.travelBooking.findUnique({
      where: { id },
      include: {
        package: true,
        customer: { select: { districtId: true, stateId: true } },
      },
    });
    if (!row) {
      throw new NotFoundException('Travel booking not found');
    }
    this.scopes.assertTravelBooking(actor, {
      customerId: row.customerId,
      customerDistrictId: row.customer.districtId,
      customerStateId: row.customer.stateId,
      packageDistrictId: row.package.districtId,
    });
    return row;
  }

  private async assertCategory(key: string) {
    const categories = await this.categories();
    if (!categories.some((row) => row.key === key)) {
      throw new BadRequestException('Unknown travel category');
    }
  }

  private assertTravelRead(actor: Actor) {
    if (
      actor.unrestricted ||
      actor.role === UserRole.ADMIN ||
      actor.role === UserRole.SUPER_ADMIN ||
      actor.role === UserRole.DISTRICT_HEAD ||
      actor.role === UserRole.STATE_HEAD ||
      actor.role === UserRole.FRANCHISE
    ) {
      return;
    }
    throw new ForbiddenException('Travel admin access required');
  }

  private assertTravelWrite(actor: Actor) {
    if (actor.unrestricted || actor.role === UserRole.ADMIN || actor.role === UserRole.SUPER_ADMIN) {
      return;
    }
    throw new ForbiddenException('Only admin can manage travel packages');
  }
}
