import { Controller, Get, Query } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { LIFECYCLE, LIFECYCLE_LABELS } from './booking-lifecycle';
import { FareEngine, RENTAL_HOURS } from './fare.engine';
import { RIDE_TYPES, VEHICLE_TYPES } from './ride-catalog';

@ApiTags('ride-engine')
@Controller('ride-engine')
export class RideEngineController {
  constructor(private readonly fares: FareEngine) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Ride types, rental packages, schedule policy, and lifecycle' })
  async catalog() {
    const [rentalPackages, schedule, transfer] = await Promise.all([
      this.fares.rentalPackages(),
      this.fares.schedulePolicy(),
      this.fares.transferPolicy(),
    ]);
    const rentalHours = [
      ...new Set(
        rentalPackages.map((row) => row.hours).filter((hours): hours is number => hours != null),
      ),
    ].sort((a, b) => a - b);
    return {
      rideTypes: RIDE_TYPES,
      vehicleTypes: VEHICLE_TYPES,
      rentalHours: rentalHours.length ? rentalHours : [...RENTAL_HOURS],
      rentalPackages,
      schedule,
      transfer,
      lifecycle: LIFECYCLE.map((key) => ({
        key,
        label: LIFECYCLE_LABELS[key],
      })),
      note: 'Fares are calculated server-side from fare_rules. Clients must display quote/fare from the API.',
    };
  }

  @Get('rental-packages')
  @ApiOperation({ summary: 'Rental hour packages from fare_rules' })
  rentalPackages(@Query('districtId') districtId?: string) {
    return this.fares.rentalPackages(districtId ? Number(districtId) : undefined);
  }
}
