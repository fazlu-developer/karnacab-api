import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { PaymentsModule } from '../payments/payments.module';
import { TravelController } from './travel.controller';
import { TravelService } from './travel.service';

@Module({
  imports: [AuthModule, PaymentsModule],
  controllers: [TravelController],
  providers: [TravelService],
  exports: [TravelService],
})
export class TravelModule {}
