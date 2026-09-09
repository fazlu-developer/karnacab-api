import { Injectable, Logger } from '@nestjs/common';
import { SmsPort } from './sms.port';

@Injectable()
export class LogSmsAdapter implements SmsPort {
  private readonly logger = new Logger(LogSmsAdapter.name);

  async send(phone: string, body: string): Promise<void> {
    this.logger.log({ phone, body: body.slice(0, 80) }, 'SMS adapter (log only)');
  }
}
