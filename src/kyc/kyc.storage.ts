import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { v2 as cloudinary } from 'cloudinary';
import { randomUUID } from 'crypto';
import { mkdir, readFile, unlink, writeFile } from 'fs/promises';
import { dirname, isAbsolute, join, normalize, resolve, sep } from 'path';

type CloudinaryResource = 'image' | 'raw';

@Injectable()
export class KycStorage {
  private readonly logger = new Logger(KycStorage.name);
  private readonly cloudName: string | null;

  constructor(private readonly config: ConfigService) {
    const url = this.config.get<string>('kyc.cloudinaryUrl') ?? '';
    this.cloudName = this.parseCloudName(url);
    if (url) {
      cloudinary.config({ cloudinary_url: url, secure: true });
    }
  }

  enabled(): boolean {
    return Boolean(this.cloudName);
  }

  publicUrl(key: string | null | undefined): string | null {
    if (!key) {
      return null;
    }
    if (key.startsWith('http://') || key.startsWith('https://')) {
      return key;
    }
    const parsed = this.parseCloudKey(key);
    if (!parsed || !this.cloudName) {
      return null;
    }
    return `https://res.cloudinary.com/${this.cloudName}/${parsed.resource}/upload/${parsed.publicId}`;
  }

  async write(
    driverId: string,
    type: string,
    buffer: Buffer,
    ext: string,
    mime?: string,
  ) {
    const contentType =
      mime ?? (ext === 'pdf' ? 'application/pdf' : `image/${ext === 'jpg' ? 'jpeg' : ext}`);
    if (this.enabled()) {
      const resource: CloudinaryResource = contentType === 'application/pdf' ? 'raw' : 'image';
      const publicId = `karnacab/kyc/${driverId}/${type}-${randomUUID()}`;
      await new Promise<void>((resolveUpload, reject) => {
        const stream = cloudinary.uploader.upload_stream(
          {
            public_id: publicId,
            resource_type: resource,
            overwrite: true,
            unique_filename: false,
          },
          (error, result) => {
            if (error || !result) {
              reject(error ?? new Error('Cloudinary upload failed'));
              return;
            }
            resolveUpload();
          },
        );
        stream.end(buffer);
      });
      return this.cloudKey(resource, publicId);
    }
    const key = `${driverId}/${type}-${randomUUID()}.${ext}`;
    const full = this.resolveKey(key);
    await mkdir(dirname(full), { recursive: true });
    await writeFile(full, buffer, { mode: 0o600 });
    return key;
  }

  async read(key: string) {
    const url = this.publicUrl(key);
    if (url) {
      const response = await fetch(url);
      if (!response.ok) {
        throw new Error(`Failed to fetch KYC file (${response.status})`);
      }
      return Buffer.from(await response.arrayBuffer());
    }
    return readFile(this.resolveKey(key));
  }

  async remove(key: string) {
    const parsed = this.parseCloudKey(key);
    if (parsed) {
      try {
        await cloudinary.uploader.destroy(parsed.publicId, {
          resource_type: parsed.resource,
          invalidate: true,
        });
      } catch (error) {
        this.logger.warn(`Cloudinary destroy failed: ${(error as Error).message}`);
      }
      return;
    }
    try {
      await unlink(this.resolveKey(key));
    } catch {
      /* already gone */
    }
  }

  private cloudKey(resource: CloudinaryResource, publicId: string) {
    return `cld:${resource}:${publicId}`;
  }

  private parseCloudKey(key: string): { resource: CloudinaryResource; publicId: string } | null {
    if (key.startsWith('cld:')) {
      const [, resource, publicId] = key.split(':', 3);
      if ((resource === 'image' || resource === 'raw') && publicId) {
        return { resource, publicId };
      }
    }
    const fromUrl = key.match(
      /res\.cloudinary\.com\/[^/]+\/(image|raw)\/upload\/(?:v\d+\/)?(.+?)(?:\.[a-z0-9]+)?$/i,
    );
    if (fromUrl) {
      return {
        resource: fromUrl[1] === 'raw' ? 'raw' : 'image',
        publicId: fromUrl[2],
      };
    }
    return null;
  }

  private parseCloudName(url: string): string | null {
    const match = url.match(/^cloudinary:\/\/[^@]+@([^/]+)/);
    return match?.[1] ?? process.env.CLOUDINARY_CLOUD_NAME ?? null;
  }

  private root(): string {
    const configured = this.config.get<string>('kyc.storageDir') ?? 'storage/kyc';
    return isAbsolute(configured) ? configured : join(process.cwd(), configured);
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
