import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { LocationModule } from '../location/location.module';
import { SupportModule } from '../support/support.module';
import { KycModule } from '../kyc/kyc.module';
import { SafetyController } from './safety.controller';
import { SafetyService } from './safety.service';

@Module({
  imports: [AuthModule, LocationModule, SupportModule, KycModule],
  controllers: [SafetyController],
  providers: [SafetyService],
  exports: [SafetyService],
})
export class SafetyModule {}
