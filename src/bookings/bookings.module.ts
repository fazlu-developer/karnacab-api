import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { QuotesModule } from '../quotes/quotes.module';
import { RideEngineModule } from '../ride-engine/ride-engine.module';
import { BookingsController } from './bookings.controller';
import { BookingsService } from './bookings.service';

import { LocationModule } from '../location/location.module';
import { WalletsModule } from '../wallets/wallets.module';
import { PaymentsModule } from '../payments/payments.module';
import { ExperienceModule } from '../experience/experience.module';

@Module({
  imports: [AuthModule, QuotesModule, RideEngineModule, LocationModule, WalletsModule, PaymentsModule, ExperienceModule],
  controllers: [BookingsController],
  providers: [BookingsService],
  exports: [BookingsService],
})
export class BookingsModule {}
