import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { SupportController } from './support.controller';
import { SupportService } from './support.service';
import { SupportStorage } from './support.storage';

@Module({
  imports: [AuthModule],
  controllers: [SupportController],
  providers: [SupportService, SupportStorage],
  exports: [SupportService],
})
export class SupportModule {}
