import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { BookingsModule } from '../bookings/bookings.module';
import { BulkModule } from '../bulk/bulk.module';
import { DriversModule } from '../drivers/drivers.module';
import { VehiclesModule } from '../vehicles/vehicles.module';
import { WalletsModule } from '../wallets/wallets.module';
import { DistrictHeadController } from './district-head.controller';
import { DistrictHeadService } from './district-head.service';

@Module({
  imports: [AuthModule, BookingsModule, DriversModule, VehiclesModule, BulkModule, WalletsModule],
  controllers: [DistrictHeadController],
  providers: [DistrictHeadService],
})
export class DistrictHeadModule {}
