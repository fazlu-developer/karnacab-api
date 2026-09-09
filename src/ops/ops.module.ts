import { Module } from '@nestjs/common';
import { BookingsModule } from '../bookings/bookings.module';
import { DriversModule } from '../drivers/drivers.module';
import { BulkModule } from '../bulk/bulk.module';
import { TravelModule } from '../travel/travel.module';
import { VehiclesModule } from '../vehicles/vehicles.module';
import { OpsController } from './ops.controller';
import { OpsService } from './ops.service';
import { OpsAdminService } from './ops-admin.service';

import { LocationModule } from '../location/location.module';
import { WalletsModule } from '../wallets/wallets.module';

@Module({
  imports: [BookingsModule, DriversModule, VehiclesModule, TravelModule, BulkModule, LocationModule, WalletsModule],
  controllers: [OpsController],
  providers: [OpsService, OpsAdminService],
})
export class OpsModule {}
