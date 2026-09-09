import { Injectable, NotFoundException } from '@nestjs/common';
import { VehicleCategory } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';

export type BulkQuoteInput = {
  eventKey: string;
  category: VehicleCategory;
  vehicleCount: number;
  advancePercent: number;
};

@Injectable()
export class BulkQuoteEngine {
  constructor(private readonly prisma: PrismaService) {}

  async quote(input: BulkQuoteInput) {
    const rule = await this.prisma.bulkRateRule.findFirst({
      where: { eventKey: input.eventKey, category: input.category, active: true },
    });
    if (!rule) {
      throw new NotFoundException('No bulk rate for that event and vehicle');
    }
    const vehicles = Math.max(1, input.vehicleCount);
    const subtotal = vehicles * rule.perVehiclePaise;
    const gstPaise = Math.round((subtotal * rule.gstPercent) / 100);
    const totalPaise = subtotal + gstPaise;
    const advancePaise = Math.round((totalPaise * input.advancePercent) / 100);
    return {
      source: 'server' as const,
      currency: 'INR',
      eventKey: input.eventKey,
      category: input.category,
      vehicleCount: vehicles,
      perVehiclePaise: rule.perVehiclePaise,
      gstPercent: rule.gstPercent,
      advancePercent: input.advancePercent,
      lines: [
        { key: 'vehicles', label: 'Vehicles', paise: subtotal, rupees: subtotal / 100 },
        { key: 'gst', label: 'GST', paise: gstPaise, rupees: gstPaise / 100, percent: rule.gstPercent },
        { key: 'total', label: 'Total', paise: totalPaise, rupees: totalPaise / 100 },
        { key: 'advance', label: 'Advance', paise: advancePaise, rupees: advancePaise / 100 },
      ],
      totalPaise,
      totalRupees: totalPaise / 100,
      advancePaise,
      advanceRupees: advancePaise / 100,
      balancePaise: totalPaise - advancePaise,
      note: 'Bulk quotation from admin bulk_rate_rules.',
    };
  }
}
