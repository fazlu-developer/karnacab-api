import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { BookingsModule } from '../bookings/bookings.module';
import { ParcelsModule } from '../parcels/parcels.module';
import { KycModule } from '../kyc/kyc.module';
import { DriversController } from './drivers.controller';
import { DriversService } from './drivers.service';
import { DriverDashboardService } from './driver-dashboard.service';
import { DriverCareService } from './driver-care.service';

import { LocationModule } from '../location/location.module';
import { WalletsModule } from '../wallets/wallets.module';
import { SafetyModule } from '../safety/safety.module';
import { SupportModule } from '../support/support.module';

@Module({
  imports: [AuthModule, BookingsModule, ParcelsModule, KycModule, LocationModule, WalletsModule, SafetyModule, SupportModule],
  controllers: [DriversController],
  providers: [DriversService, DriverDashboardService, DriverCareService],
  exports: [DriversService, DriverDashboardService, DriverCareService],
})
export class DriversModule {}
