import { Injectable, NotFoundException } from '@nestjs/common';
import { RideProduct, VehicleCategory } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { bucketsFromBreakdown, settleCommission } from './commission.engine';

export const RENTAL_HOURS = [2, 4, 6, 8, 12] as const;

export type FareQuoteInput = {
  product: RideProduct;
  category: VehicleCategory;
  distanceKm: number;
  waitMinutes?: number;
  night?: boolean;
  tollPaise?: number;
  parkingPaise?: number;
  districtId?: number;
  hours?: number;
  extraHours?: number;
  stopCount?: number;
  roundTrip?: boolean;
  nightStayNights?: number;
};

export type FareLine = {
  key: string;
  label: string;
  paise: number;
  rupees: number;
  km?: number;
  hours?: number;
  percent?: number;
  includedInTotal: boolean;
};

@Injectable()
export class FareEngine {
  constructor(private readonly prisma: PrismaService) {}

  async quote(input: FareQuoteInput) {
    const hours = input.product === RideProduct.RENTAL ? (input.hours ?? 8) : input.hours;
    const rule = await this.prisma.fareRule.findFirst({
      where: {
        product: input.product,
        category: input.category,
        active: true,
        ...(input.districtId
          ? { OR: [{ districtId: input.districtId }, { districtId: null }] }
          : {}),
        ...(input.product === RideProduct.RENTAL ? { rentalHours: hours } : {}),
      },
      orderBy: { districtId: 'desc' },
    });

    if (!rule) {
      throw new NotFoundException('No fare rule for that product and vehicle');
    }

    const minKm = Number(rule.minKm);
    const includedKm = Number(rule.includedKm);
    const billedKm = this.billedKm(input, minKm);
    const extraKm = Math.max(0, billedKm - includedKm);
    const packagePaise = Math.round(includedKm * rule.perKmPaise);
    const basePaise = Math.round(Math.min(minKm, includedKm) * rule.perKmPaise);
    const distancePaise = packagePaise - basePaise;
    const extraPaise = Math.round(extraKm * rule.extraKmPaise);
    const waitingPaise = (input.waitMinutes ?? 0) * rule.waitingPaise;
    const extraHourPaiseRate = rule.extraHourPaise ?? 15000;
    const extraHours = input.product === RideProduct.RENTAL ? Math.max(0, input.extraHours ?? 0) : 0;
    const rentalExtraPaise = extraHours * extraHourPaiseRate;
    const perStopPaise =
      rule.stopPaise ?? (input.product === RideProduct.MULTI_STOP ? 1500 : 0);
    const stopCount =
      input.product === RideProduct.MULTI_STOP ? Math.max(0, input.stopCount ?? 0) : 0;
    const stopPaise = stopCount * perStopPaise;
    const nights = input.product === RideProduct.ROUND_WAY ? Math.max(0, input.nightStayNights ?? 0) : 0;
    const nightStayPaise = nights * (rule.nightStayPaise ?? 0);
    const driverAllowPaise = input.product === RideProduct.ROUND_WAY ? rule.driverAllowPaise : 0;
    const preNight =
      basePaise +
      distancePaise +
      extraPaise +
      waitingPaise +
      driverAllowPaise +
      nightStayPaise +
      rentalExtraPaise +
      stopPaise;
    const nightPaise = input.night ? Math.round((preNight * rule.nightPercent) / 100) : 0;
    const taxable = preNight + nightPaise;
    const toll = rule.applyToll ? (input.tollPaise ?? 0) : 0;
    const parking = rule.applyParking ? (input.parkingPaise ?? 0) : 0;
    const gstBase = rule.applyGstToBase ? taxable : 0;
    const gstPaise = Math.round((gstBase * rule.gstPercent) / 100);
    const beforeDiscount = taxable + toll + parking + gstPaise;
    const discountPaise = this.discountPaise(rule, beforeDiscount);
    const totalPaise = Math.max(0, beforeDiscount - discountPaise);

    const commission = await this.prisma.commissionRule.findFirst({
      where: { active: true, name: 'default' },
    });
    const buckets = bucketsFromBreakdown({
      basePaise,
      distancePaise,
      extraPaise,
      waitingPaise,
      nightPaise,
      driverAllowPaise,
      nightStayPaise,
      rentalExtraPaise,
      stopPaise,
      gstPaise,
      tollPaise: toll,
      parkingPaise: parking,
      discountPaise,
    });
    const settled = settleCommission(buckets, commission, totalPaise);
    const commissionPercent = settled.percent;
    const commissionPaise = settled.commissionPaise;

    const lines = this.linesFor(input.product, {
      basePaise,
      distancePaise,
      extraPaise,
      waitingPaise,
      nightPaise,
      nightPercent: input.night ? rule.nightPercent : 0,
      toll,
      parking,
      gstPaise,
      gstPercent: rule.gstPercent,
      discountPaise,
      totalPaise,
      billedKm,
      extraKm,
      minKm,
      includedKm,
      driverAllowPaise,
      nightStayPaise,
      nights,
      packagePaise,
      rentalHours: rule.rentalHours,
      rentalExtraPaise,
      extraHours,
      stopPaise,
      stopCount,
    });

    return {
      currency: 'INR',
      product: input.product,
      category: input.category,
      billedKm,
      extraKm,
      hours: rule.rentalHours ?? hours ?? null,
      extraHours,
      nightStayNights: nights,
      source: 'server' as const,
      rates: {
        minKm,
        includedKm,
        perKmPaise: rule.perKmPaise,
        extraKmPaise: rule.extraKmPaise,
        waitingPaisePerMin: rule.waitingPaise,
        nightPercent: rule.nightPercent,
        gstPercent: rule.gstPercent,
        cancelPaise: rule.cancelPaise,
        discountPaise: this.ruleDiscountPaise(rule),
        discountPercent: this.ruleDiscountPercent(rule),
        driverAllowPaise: rule.driverAllowPaise,
        nightStayPaise: rule.nightStayPaise ?? 0,
        extraHourPaise: extraHourPaiseRate,
        rentalHours: rule.rentalHours,
        stopPaise: perStopPaise,
        applyToll: rule.applyToll,
        applyParking: rule.applyParking,
        applyGstToBase: rule.applyGstToBase,
      },
      breakdown: {
        basePaise,
        distancePaise,
        extraPaise,
        waitingPaise,
        nightPaise,
        driverAllowPaise,
        nightStayPaise,
        rentalExtraPaise,
        stopPaise,
        nightPercent: input.night ? rule.nightPercent : 0,
        gstPaise,
        tollPaise: toll,
        parkingPaise: parking,
        discountPaise,
        cancelPaise: rule.cancelPaise,
        lines,
      },
      lines,
      totalPaise,
      totalRupees: totalPaise / 100,
      listRupees: beforeDiscount / 100,
      discountRupees: discountPaise / 100,
      commissionPercent,
      commissionPaise,
      commission: settled,
      note: 'Quote from admin fare_rules. Driver is not assigned.',
    };
  }

  async rentalPackages(districtId?: number) {
    const rows = await this.prisma.fareRule.findMany({
      where: {
        product: RideProduct.RENTAL,
        active: true,
        ...(districtId ? { OR: [{ districtId }, { districtId: null }] } : {}),
      },
      orderBy: [{ rentalHours: 'asc' }, { category: 'asc' }],
    });
    return rows.map((row) => ({
      hours: row.rentalHours,
      category: row.category,
      includedKm: Number(row.includedKm),
      pricePaise: Math.round(Number(row.includedKm) * row.perKmPaise),
      priceRupees: Math.round(Number(row.includedKm) * row.perKmPaise) / 100,
      extraKmPaise: row.extraKmPaise,
      extraHourPaise: row.extraHourPaise ?? 15000,
    }));
  }

  async transferPolicy() {
    const maxStops = await this.settingInt('multi_stop_max', 3);
    return {
      airport: {
        placeTypes: ['airport'],
        requiresFlightNumber: true,
        requiresScheduledAt: true,
        allowsTerminal: true,
      },
      railway: {
        placeTypes: ['train_station'],
        requiresTrainNumber: true,
        requiresScheduledAt: true,
      },
      multiStop: {
        minStops: 1,
        maxStops,
      },
    };
  }

  async schedulePolicy() {
    const reminder = await this.settingInt('schedule_reminder_minutes', 60);
    const assignLead = await this.settingInt('schedule_assign_lead_minutes', 120);
    return {
      reminderMinutes: reminder,
      assignLeadMinutes: assignLead,
      allowsReschedule: true,
      allowsCancel: true,
      requiresFuturePickup: true,
    };
  }

  private async settingInt(key: string, fallback: number) {
    const row = await this.prisma.systemSetting.findUnique({ where: { key } });
    const parsed = Number(row?.value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
  }

  private billedKm(input: FareQuoteInput, minKm: number) {
    let km = Math.max(input.distanceKm, minKm);
    const roundTrip = input.product === RideProduct.ROUND_WAY && input.roundTrip !== false;
    if (roundTrip) {
      km = Math.max(km * 2, minKm);
    }
    return km;
  }

  private ruleDiscountPaise(rule: { discountPaise?: number }) {
    return rule.discountPaise ?? 0;
  }

  private ruleDiscountPercent(rule: { discountPercent?: number }) {
    return rule.discountPercent ?? 0;
  }

  private discountPaise(rule: { discountPaise?: number; discountPercent?: number }, beforeDiscount: number) {
    const flat = this.ruleDiscountPaise(rule);
    const percent = Math.round((beforeDiscount * this.ruleDiscountPercent(rule)) / 100);
    return Math.min(beforeDiscount, flat + percent);
  }

  private linesFor(
    product: RideProduct,
    parts: {
      basePaise: number;
      distancePaise: number;
      extraPaise: number;
      waitingPaise: number;
      nightPaise: number;
      nightPercent: number;
      toll: number;
      parking: number;
      gstPaise: number;
      gstPercent: number;
      discountPaise: number;
      totalPaise: number;
      billedKm: number;
      extraKm: number;
      minKm: number;
      includedKm: number;
      driverAllowPaise: number;
      nightStayPaise: number;
      nights: number;
      packagePaise: number;
      rentalHours: number | null;
      rentalExtraPaise: number;
      extraHours: number;
      stopPaise: number;
      stopCount: number;
    },
  ): FareLine[] {
    if (product === RideProduct.RENTAL) {
      return [
        this.line('package', 'Package', parts.packagePaise, {
          km: parts.includedKm,
          hours: parts.rentalHours ?? undefined,
        }),
        this.line('extraKm', 'Extra KM', parts.extraPaise, { km: parts.extraKm }),
        this.line('extraHour', 'Extra hour', parts.rentalExtraPaise, { hours: parts.extraHours }),
        this.line('toll', 'Toll', parts.toll),
        this.line('parking', 'Parking', parts.parking),
        this.line('gst', 'GST', parts.gstPaise, { percent: parts.gstPercent }),
        this.line('discount', 'Discount', -parts.discountPaise),
        this.line('total', 'Total', parts.totalPaise, { includedInTotal: false }),
      ];
    }
    if (product === RideProduct.ROUND_WAY) {
      return [
        this.line('base', 'Base', parts.basePaise, { km: Math.min(parts.minKm, parts.includedKm) }),
        this.line('distance', 'Distance', parts.distancePaise, { km: parts.billedKm }),
        this.line('extraKm', 'Extra KM', parts.extraPaise, { km: parts.extraKm }),
        this.line('driverAllow', 'Driver allowance', parts.driverAllowPaise),
        this.line('nightStay', 'Night stay', parts.nightStayPaise, { hours: parts.nights }),
        this.line('night', 'Night', parts.nightPaise, { percent: parts.nightPercent }),
        this.line('toll', 'Toll', parts.toll),
        this.line('parking', 'Parking', parts.parking),
        this.line('gst', 'GST', parts.gstPaise, { percent: parts.gstPercent }),
        this.line('discount', 'Discount', -parts.discountPaise),
        this.line('total', 'Total', parts.totalPaise, { includedInTotal: false }),
      ];
    }
    if (product === RideProduct.MULTI_STOP) {
      return [
        this.line('base', 'Base', parts.basePaise, { km: Math.min(parts.minKm, parts.includedKm) }),
        this.line('distance', 'Distance', parts.distancePaise, { km: parts.billedKm }),
        this.line('extraKm', 'Extra KM', parts.extraPaise, { km: parts.extraKm }),
        this.line('stops', 'Stops', parts.stopPaise),
        this.line('waiting', 'Waiting', parts.waitingPaise),
        this.line('night', 'Night', parts.nightPaise, { percent: parts.nightPercent }),
        this.line('toll', 'Toll', parts.toll),
        this.line('parking', 'Parking', parts.parking),
        this.line('gst', 'GST', parts.gstPaise, { percent: parts.gstPercent }),
        this.line('discount', 'Discount', -parts.discountPaise),
        this.line('total', 'Total', parts.totalPaise, { includedInTotal: false }),
      ];
    }
    return [
      this.line('base', 'Base', parts.basePaise, { km: Math.min(parts.minKm, parts.includedKm) }),
      this.line('distance', 'Distance', parts.distancePaise, { km: parts.billedKm }),
      this.line('extraKm', 'Extra KM', parts.extraPaise, { km: parts.extraKm }),
      this.line('waiting', 'Waiting', parts.waitingPaise),
      this.line('night', 'Night', parts.nightPaise, { percent: parts.nightPercent }),
      this.line('toll', 'Toll', parts.toll),
      this.line('parking', 'Parking', parts.parking),
      this.line('gst', 'GST', parts.gstPaise, { percent: parts.gstPercent }),
      this.line('discount', 'Discount', -parts.discountPaise),
      this.line('total', 'Total', parts.totalPaise, { includedInTotal: false }),
    ];
  }

  private line(
    key: string,
    label: string,
    paise: number,
    extra?: { km?: number; hours?: number; percent?: number; includedInTotal?: boolean },
  ): FareLine {
    return {
      key,
      label,
      paise,
      rupees: paise / 100,
      km: extra?.km,
      hours: extra?.hours,
      percent: extra?.percent,
      includedInTotal: extra?.includedInTotal ?? true,
    };
  }
}
