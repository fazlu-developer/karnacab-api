import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';

export type PolicyItem = { key: string; label: string };

@Injectable()
export class ParcelPolicy {
  constructor(private readonly prisma: PrismaService) {}

  async catalog() {
    const [types, prohibited, rules] = await Promise.all([
      this.jsonList('parcel_types', [
        { key: 'documents', label: 'Documents' },
        { key: 'electronics', label: 'Electronics' },
        { key: 'clothes', label: 'Clothes' },
        { key: 'food', label: 'Packed food' },
        { key: 'household', label: 'Household' },
        { key: 'other', label: 'Other permitted goods' },
      ]),
      this.jsonList('parcel_prohibited_goods', [
        { key: 'flammables', label: 'Flammable liquids or gases' },
        { key: 'explosives', label: 'Explosives' },
        { key: 'weapons', label: 'Weapons or ammunition' },
        { key: 'drugs', label: 'Illegal drugs' },
        { key: 'cash', label: 'Cash, gold, or jewellery' },
        { key: 'live_animals', label: 'Live animals' },
        { key: 'hazardous', label: 'Hazardous chemicals' },
      ]),
      this.prisma.parcelFareRule.findMany({
        where: { active: true },
        orderBy: [{ lane: 'asc' }, { category: 'asc' }],
      }),
    ]);
    return {
      lanes: [
        { key: 'LOCAL', label: 'Local Parcel', biharLane: false },
        { key: 'BIHAR', label: 'Bihar Parcel', biharLane: true },
      ],
      types,
      prohibited,
      vehicles: [...new Set(rules.map((row) => row.category))],
      fareRules: rules.map((row) => ({
        lane: row.lane,
        category: row.category,
        minKm: Number(row.minKm),
        includedKm: Number(row.includedKm),
        perKmPaise: row.perKmPaise,
        extraKmPaise: row.extraKmPaise,
        perKgPaise: row.perKgPaise,
        minChargePaise: row.minChargePaise,
        gstPercent: row.gstPercent,
      })),
      tracking: [
        'Created',
        'Assigned',
        'Picked Up',
        'In Transit',
        'Destination',
        'Out for Delivery',
        'Delivered',
      ],
      complianceText:
        'I confirm this parcel does not contain prohibited goods and complies with KarnaCab parcel policy.',
    };
  }

  async isProhibited(parcelType: string) {
    const catalog = await this.catalog();
    return catalog.prohibited.some((row) => row.key === parcelType);
  }

  async isAllowedType(parcelType: string) {
    const catalog = await this.catalog();
    return catalog.types.some((row) => row.key === parcelType);
  }

  private async jsonList(key: string, fallback: PolicyItem[]) {
    const row = await this.prisma.systemSetting.findUnique({ where: { key } });
    if (!row?.value) {
      return fallback;
    }
    try {
      const parsed = JSON.parse(row.value) as PolicyItem[];
      return Array.isArray(parsed) && parsed.length ? parsed : fallback;
    } catch {
      return fallback;
    }
  }
}
