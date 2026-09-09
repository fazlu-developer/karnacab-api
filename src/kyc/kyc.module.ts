import { Module } from '@nestjs/common';
import { KycController } from './kyc.controller';
import { KycService } from './kyc.service';
import { KycStorage } from './kyc.storage';

@Module({
  controllers: [KycController],
  providers: [KycService, KycStorage],
  exports: [KycService, KycStorage],
})
export class KycModule {}
