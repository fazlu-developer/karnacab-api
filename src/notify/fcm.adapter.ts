import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import * as admin from 'firebase-admin';

@Injectable()
export class FcmAdapter {
  private readonly logger = new Logger(FcmAdapter.name);
  private app: admin.app.App | null = null;

  constructor(private readonly config: ConfigService) {}

  configured() {
    return Boolean(this.credentials());
  }

  projectId() {
    return (
      this.config.get<string>('firebase.projectId')?.trim() ||
      this.credentials()?.project_id ||
      ''
    );
  }

  async send(
    tokens: string[],
    title: string,
    body: string,
    data: Record<string, string> = {},
  ) {
    if (!tokens.length) {
      return { sent: 0, skipped: true };
    }
    const app = this.initialize();
    if (!app) {
      return { sent: 0, skipped: true };
    }
    try {
      const payload: admin.messaging.MulticastMessage = {
        tokens,
        notification: { title, body },
        data,
        android: {
          priority: 'high',
          notification: {
            channelId: 'karnacab_default',
            sound: 'default',
          },
        },
        apns: {
          payload: {
            aps: { sound: 'default' },
          },
        },
      };
      const result = await app.messaging().sendEachForMulticast(payload);
      if (result.failureCount > 0) {
        this.logger.warn(
          `FCM sent ${result.successCount}/${tokens.length} (failed ${result.failureCount})`,
        );
      }
      return { sent: result.successCount, skipped: false };
    } catch (error) {
      this.logger.warn({ err: error }, 'FCM send skipped');
      return { sent: 0, skipped: true };
    }
  }

  private initialize() {
    if (this.app) {
      return this.app;
    }
    const creds = this.credentials();
    if (!creds) {
      return null;
    }
    const existing = admin.apps[0];
    if (existing) {
      this.app = existing;
      return this.app;
    }
    this.app = admin.initializeApp({
      credential: admin.credential.cert(creds as admin.ServiceAccount),
      projectId: this.projectId() || creds.project_id,
    });
    this.logger.log(`FCM ready for project ${this.projectId() || creds.project_id}`);
    return this.app;
  }

  private credentials(): (Record<string, string> & { project_id?: string }) | null {
    const raw = this.config.get<string>('firebase.serviceAccountJson')?.trim();
    if (raw) {
      try {
        return JSON.parse(raw) as Record<string, string> & { project_id?: string };
      } catch {
        this.logger.warn('FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON');
      }
    }
    const pathValue = this.config.get<string>('firebase.serviceAccountPath')?.trim();
    const candidates = [
      pathValue,
      process.env.GOOGLE_APPLICATION_CREDENTIALS,
      resolve(process.cwd(), 'firebase-service-account.json'),
    ].filter((item): item is string => Boolean(item?.trim()));
    for (const file of candidates) {
      const abs = resolve(file);
      if (!existsSync(abs)) {
        continue;
      }
      try {
        return JSON.parse(readFileSync(abs, 'utf8')) as Record<string, string> & {
          project_id?: string;
        };
      } catch {
        this.logger.warn(`Could not read Firebase service account at ${abs}`);
      }
    }
    return null;
  }
}
