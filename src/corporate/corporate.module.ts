import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { PaymentsModule } from '../payments/payments.module';
import { SupportModule } from '../support/support.module';
import { CorporateController } from './corporate.controller';
import { CorporateService } from './corporate.service';

@Module({
  imports: [AuthModule, PaymentsModule, SupportModule],
  controllers: [CorporateController],
  providers: [CorporateService],
  exports: [CorporateService],
})
export class CorporateModule {}
