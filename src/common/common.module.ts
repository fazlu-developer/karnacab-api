import { APP_FILTER } from '@nestjs/core';
import { Global, Module } from '@nestjs/common';
import { AuditListener } from './audit.listener';
import { AuditService } from './audit.service';
import { DomainEvents } from './domain-events.service';
import { LogSmsAdapter } from './log-sms.adapter';
import { PublicErrorFilter } from './public-error.filter';
import { SmsPort } from './sms.port';

@Global()
@Module({
  providers: [
    DomainEvents,
    AuditService,
    AuditListener,
    { provide: SmsPort, useClass: LogSmsAdapter },
    { provide: APP_FILTER, useClass: PublicErrorFilter },
  ],
  exports: [DomainEvents, AuditService, SmsPort],
})
export class CommonModule {}
