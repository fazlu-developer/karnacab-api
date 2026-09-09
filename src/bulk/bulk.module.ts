import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { PaymentsModule } from '../payments/payments.module';
import { BulkQuoteEngine } from './bulk-quote.engine';
import { BulkController } from './bulk.controller';
import { BulkService } from './bulk.service';

@Module({
  imports: [AuthModule, PaymentsModule],
  controllers: [BulkController],
  providers: [BulkService, BulkQuoteEngine],
  exports: [BulkService],
})
export class BulkModule {}
