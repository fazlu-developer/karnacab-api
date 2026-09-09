import {
  Injectable,
  Logger,
  OnModuleDestroy,
  OnModuleInit,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import Redis from 'ioredis';

@Injectable()
export class RedisService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(RedisService.name);
  private client!: Redis;

  constructor(private readonly config: ConfigService) {}

  onModuleInit(): void {
    this.client = new Redis({
      host: this.config.get<string>('redis.host'),
      port: this.config.get<number>('redis.port'),
      password: this.config.get<string>('redis.password') || undefined,
      db: this.config.get<number>('redis.db'),
      lazyConnect: true,
      maxRetriesPerRequest: 1,
      enableReadyCheck: true,
      retryStrategy: () => null,
    });

    this.client.on('error', (error) => {
      this.logger.warn(`Redis connection error: ${error.message}`);
    });
    this.client.on('end', () => {
      this.logger.warn('Redis is unavailable; using in-memory fallback.');
    });
  }

  async onModuleDestroy(): Promise<void> {
    if (this.client) {
      await this.client.quit();
    }
  }

  getClient(): Redis {
    return this.client;
  }

  async ping(): Promise<boolean> {
    await this.ensureConnected();
    const result = await this.client.ping();
    return result === 'PONG';
  }

  async get(key: string): Promise<string | null> {
    try {
      await this.ensureConnected();
      return this.client.get(key);
    } catch {
      return this.memoryGet(key);
    }
  }

  async setex(key: string, ttlSeconds: number, value: string): Promise<void> {
    try {
      await this.ensureConnected();
      await this.client.set(key, value, 'EX', ttlSeconds);
    } catch {
      this.memory.set(key, {
        value,
        expiresAt: Date.now() + ttlSeconds * 1000,
      });
    }
  }

  async setNxEx(
    key: string,
    ttlSeconds: number,
    value: string,
  ): Promise<boolean> {
    try {
      await this.ensureConnected();
      const result = await this.client.set(key, value, 'EX', ttlSeconds, 'NX');
      return result === 'OK';
    } catch {
      this.pruneMemory();
      if (this.memory.has(key)) {
        return false;
      }
      this.memory.set(key, {
        value,
        expiresAt: Date.now() + ttlSeconds * 1000,
      });
      return true;
    }
  }

  async mget(keys: string[]): Promise<(string | null)[]> {
    if (!keys.length) {
      return [];
    }
    try {
      await this.ensureConnected();
      return this.client.mget(...keys);
    } catch {
      return keys.map((key) => this.memoryGet(key));
    }
  }

  async publish(channel: string, message: string): Promise<void> {
    try {
      await this.ensureConnected();
      await this.client.publish(channel, message);
    } catch {
      // Redis down: in-memory GET still serves last known location.
    }
  }

  async del(key: string): Promise<void> {
    try {
      await this.ensureConnected();
      await this.client.del(key);
    } catch {
      this.memory.delete(key);
    }
  }

  async incr(key: string): Promise<number> {
    try {
      await this.ensureConnected();
      return this.client.incr(key);
    } catch {
      const current = Number(this.memoryGet(key) ?? '0');
      const next = current + 1;
      const existing = this.memory.get(key);
      this.memory.set(key, {
        value: String(next),
        expiresAt: existing?.expiresAt ?? Date.now() + 300_000,
      });
      return next;
    }
  }

  private readonly memory = new Map<
    string,
    { value: string; expiresAt: number }
  >();

  private async ensureConnected(): Promise<void> {
    if (this.client.status !== 'ready' && this.client.status !== 'connecting') {
      await this.client.connect();
    }
  }

  private pruneMemory(): void {
    const now = Date.now();
    for (const [key, entry] of this.memory) {
      if (entry.expiresAt <= now) {
        this.memory.delete(key);
      }
    }
  }

  private memoryGet(key: string): string | null {
    this.pruneMemory();
    return this.memory.get(key)?.value ?? null;
  }
}
