import { mkdir, readFile, writeFile } from 'fs/promises';
import { dirname, isAbsolute, join, normalize, resolve, sep } from 'path';
import { randomUUID } from 'crypto';
import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

@Injectable()
export class SupportStorage {
  constructor(private readonly config: ConfigService) {}

  private root() {
    const configured = this.config.get<string>('support.storageDir') ?? 'storage/support';
    return isAbsolute(configured) ? configured : join(process.cwd(), configured);
  }

  async write(ticketId: string, buffer: Buffer, ext: string) {
    const key = `${ticketId}/${randomUUID()}.${ext}`;
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
