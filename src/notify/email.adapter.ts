import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

@Injectable()
export class EmailAdapter {
  private readonly logger = new Logger(EmailAdapter.name);

  constructor(private readonly config: ConfigService) {}

  configured() {
    return Boolean(this.config.get<string>('mail.from')?.trim() || this.config.get<string>('MAIL_FROM')?.trim());
  }

  async send(to: string, subject: string, body: string) {
    if (!to.trim()) {
      return { sent: 0, skipped: true };
    }
    this.logger.log({ to, subject, body: body.slice(0, 80) }, this.configured() ? 'Email queued' : 'Email adapter (log only)');
    return { sent: 1, skipped: !this.configured() };
  }
}
