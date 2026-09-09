import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { PlacesModule } from '../places/places.module';
import { PaymentsModule } from '../payments/payments.module';
import { ParcelFareEngine } from './parcel-fare.engine';
import { ParcelPolicy } from './parcel-policy';
import { ParcelsController } from './parcels.controller';
import { ParcelsService } from './parcels.service';

@Module({
  imports: [AuthModule, PlacesModule, PaymentsModule],
  controllers: [ParcelsController],
  providers: [ParcelsService, ParcelFareEngine, ParcelPolicy],
  exports: [ParcelsService],
})
export class ParcelsModule {}
