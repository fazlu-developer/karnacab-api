import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { BookingsModule } from '../bookings/bookings.module';
import { DriversModule } from '../drivers/drivers.module';
import { VehiclesModule } from '../vehicles/vehicles.module';
import { StateHeadController } from './state-head.controller';
import { StateHeadService } from './state-head.service';

@Module({
  imports: [AuthModule, BookingsModule, DriversModule, VehiclesModule],
  controllers: [StateHeadController],
  providers: [StateHeadService],
})
export class StateHeadModule {}
