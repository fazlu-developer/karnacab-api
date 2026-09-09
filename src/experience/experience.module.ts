import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { WalletsModule } from '../wallets/wallets.module';
import { PaymentsModule } from '../payments/payments.module';
import { ExperienceController } from './experience.controller';
import { ExperienceService } from './experience.service';

@Module({
  imports: [AuthModule, WalletsModule, PaymentsModule],
  controllers: [ExperienceController],
  providers: [ExperienceService],
  exports: [ExperienceService],
})
export class ExperienceModule {}
