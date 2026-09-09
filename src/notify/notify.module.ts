import { Global, Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { FcmAdapter } from './fcm.adapter';
import { EmailAdapter } from './email.adapter';
import { NotificationsController } from './notifications.controller';
import { NotificationsListener } from './notifications.listener';
import { NotificationsService } from './notifications.service';

@Global()
@Module({
  imports: [AuthModule],
  controllers: [NotificationsController],
  providers: [FcmAdapter, EmailAdapter, NotificationsService, NotificationsListener],
  exports: [NotificationsService, FcmAdapter, EmailAdapter],
})
export class NotifyModule {}
