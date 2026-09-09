import { mkdir, readFile, writeFile } from 'fs/promises';
import { dirname, isAbsolute, join, normalize, resolve, sep } from 'path';
import { randomUUID } from 'crypto';
import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

@Injectable()
export class AdsStorage {
  constructor(private readonly config: ConfigService) {}

  private root(): string {
    const configured = this.config.get<string>('ads.storageDir') ?? 'storage/ads';
    return isAbsolute(configured) ? configured : join(process.cwd(), configured);
  }

  async write(campaignId: string, buffer: Buffer, ext: string) {
    const key = `${campaignId}/banner-${randomUUID()}.${ext}`;
    const full = this.resolveKey(key);
    await mkdir(dirname(full), { recursive: true });
    await writeFile(full, buffer, { mode: 0o600 });
    return key;
  }

  async read(key: string) {
    return readFile(this.resolveKey(key));
  }

  private resolveKey(key: string) {
    const root = this.root();
    const full = resolve(root, key);
    const prefix = normalize(root + sep);
    if (!full.startsWith(prefix) && full !== root) {
      throw new Error('Invalid storage key');
    }
    return full;
  }
}
