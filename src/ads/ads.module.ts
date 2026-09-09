import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { AdsController } from './ads.controller';
import { AdsService } from './ads.service';
import { AdsStorage } from './ads.storage';

@Module({
  imports: [AuthModule],
  controllers: [AdsController],
  providers: [AdsService, AdsStorage],
  exports: [AdsService],
})
export class AdsModule {}
