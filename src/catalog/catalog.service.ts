import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';
import { RIDE_TYPES, VEHICLE_TYPES } from '../ride-engine/ride-catalog';
import { presentTravelPackage } from '../travel/travel.presenter';
import { parseCategoryList } from '../travel/travel-categories';

@Injectable()
export class CatalogService {
  constructor(private readonly prisma: PrismaService) {}

  async listPublic() {
    const payload = await this.bundle();
    const { dummyAccounts: _omit, ...publicCatalog } = payload;
    return publicCatalog;
  }

  async list() {
    return this.bundle();
  }

  private async bundle() {
    const [services, packages, districts, categorySetting, promoSetting] = await Promise.all([
      this.prisma.catalogService.findMany({
        where: { active: true },
        orderBy: [{ group: 'asc' }, { sortOrder: 'asc' }],
      }),
      this.prisma.travelPackage.findMany({
        where: { status: 'PUBLISHED' },
        orderBy: { id: 'asc' },
      }),
      this.prisma.district.findMany({
        select: { id: true, name: true, code: true },
        orderBy: { name: 'asc' },
      }),
      this.prisma.systemSetting.findUnique({ where: { key: 'travel_categories' } }),
      this.prisma.systemSetting.findUnique({ where: { key: 'cms_home_promo' } }),
    ]);

    const ride = services.filter((row) => row.group === 'RIDE');
    const parcel = services.filter((row) => row.group === 'PARCEL');

    return {
      tabs: [
        { key: 'ride', title: 'KarnaCab' },
        { key: 'parcel', title: 'Parcel' },
      ],
      rideServices: ride,
      parcelServices: parcel,
      promo: this.parsePromo(promoSetting?.value),
      categories: [
        { key: 'ride', title: 'Ride', subtitle: 'Cab, bike, auto across Bihar' },
        { key: 'parcel', title: 'Parcel', subtitle: 'Local and district delivery' },
        { key: 'travel', title: 'Travel', subtitle: 'Darshan, tours, hotel + cab' },
        { key: 'bulk', title: 'Bulk', subtitle: 'Weddings, schools, events' },
        { key: 'corporate', title: 'Corporate', subtitle: 'Company GST billing' },
      ],
      districts,
      packages: packages.map((row) => presentTravelPackage(row, parseCategoryList(categorySetting?.value))),
      travelCategories: parseCategoryList(categorySetting?.value),
      rideTypes: RIDE_TYPES.map((row) => ({ key: row.key, title: row.label })),
      vehicleTypes: VEHICLE_TYPES.map((row) => ({ key: row.key, title: row.label })),
      dummyAccounts: {
        customer: { phone: '9999999999', otp: '123456', name: 'Fazlu' },
        driver: { phone: '9888888888', otp: '123456', name: 'Rakesh Kumar' },
      },
    };
  }

  private parsePromo(raw?: string | null) {
    const fallback = {
      title: 'Railway station pickup',
      cta: 'See railway',
      subtitle: 'Station transfers across Bihar',
      href: '/railway',
    };
    if (!raw) {
      return fallback;
    }
    try {
      return { ...fallback, ...(JSON.parse(raw) as Record<string, string>) };
    } catch {
      return fallback;
    }
  }
}
