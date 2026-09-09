import { Injectable, NotFoundException } from '@nestjs/common';
import { VehicleCategory } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';

export type ParcelQuoteInput = {
  biharLane: boolean;
  category: VehicleCategory;
  distanceKm: number;
  weightKg: number;
  lengthCm?: number;
  widthCm?: number;
  heightCm?: number;
  quantity?: number;
};

@Injectable()
export class ParcelFareEngine {
  constructor(private readonly prisma: PrismaService) {}

  async quote(input: ParcelQuoteInput) {
    const lane = input.biharLane ? 'BIHAR' : 'LOCAL';
    const rule = await this.prisma.parcelFareRule.findFirst({
      where: { lane, category: input.category, active: true },
    });
    if (!rule) {
      throw new NotFoundException('No parcel fare rule for that lane and vehicle');
    }
    const qty = Math.max(1, input.quantity ?? 1);
    const actualKg = Math.max(0.1, input.weightKg) * qty;
    const volumetric =
      input.lengthCm && input.widthCm && input.heightCm
        ? (Number(input.lengthCm) * Number(input.widthCm) * Number(input.heightCm) * qty) /
          rule.volumetricDivisor
        : 0;
    const chargeableKg = Math.max(actualKg, volumetric);
    const minKm = Number(rule.minKm);
    const includedKm = Number(rule.includedKm);
    const billedKm = Math.max(input.distanceKm, minKm);
    const extraKm = Math.max(0, billedKm - includedKm);
    const distancePaise = Math.round(includedKm * rule.perKmPaise + extraKm * rule.extraKmPaise);
    const weightPaise = Math.round(chargeableKg * rule.perKgPaise);
    const subtotal = Math.max(rule.minChargePaise, distancePaise + weightPaise);
    const gstPaise = Math.round((subtotal * rule.gstPercent) / 100);
    const totalPaise = subtotal + gstPaise;
    const lines = [
      { key: 'distance', label: 'Distance', paise: distancePaise, rupees: distancePaise / 100, km: billedKm },
      { key: 'weight', label: 'Weight', paise: weightPaise, rupees: weightPaise / 100 },
      { key: 'gst', label: 'GST', paise: gstPaise, rupees: gstPaise / 100, percent: rule.gstPercent },
      { key: 'total', label: 'Total', paise: totalPaise, rupees: totalPaise / 100 },
    ];
    return {
      source: 'server' as const,
      currency: 'INR',
      lane,
      category: input.category,
      billedKm,
      chargeableKg: Math.round(chargeableKg * 100) / 100,
      rates: {
        minKm,
        includedKm,
        perKmPaise: rule.perKmPaise,
        extraKmPaise: rule.extraKmPaise,
        perKgPaise: rule.perKgPaise,
        minChargePaise: rule.minChargePaise,
        gstPercent: rule.gstPercent,
        volumetricDivisor: rule.volumetricDivisor,
      },
      lines,
      totalPaise,
      totalRupees: totalPaise / 100,
      note: 'Parcel fare from admin parcel_fare_rules.',
    };
  }
}
