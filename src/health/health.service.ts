import { Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { PrismaService } from '../prisma/prisma.service';
import { RedisService } from '../redis/redis.service';

@Injectable()
export class HealthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly redis: RedisService,
    private readonly config: ConfigService,
  ) {}

  async check() {
    const [database, cache] = await Promise.all([
      this.checkDatabase(),
      this.checkRedis(),
    ]);

    const status =
      database.status === 'up' && cache.status === 'up' ? 'ok' : 'degraded';

    return {
      status,
      service: this.config.get<string>('app.name'),
      version: this.config.get<string>('app.version'),
      prefix: `/${this.config.get<string>('app.prefix')}`,
      timestamp: new Date().toISOString(),
      checks: {
        database,
        redis: cache,
      },
    };
  }

  private async checkDatabase() {
    try {
      await this.prisma.ping();
      return {
        status: 'up' as const,
        host: this.config.get<string>('database.host'),
        port: this.config.get<number>('database.port'),
        name: this.config.get<string>('database.name'),
      };
    } catch (error) {
      return {
        status: 'down' as const,
        message: error instanceof Error ? error.message : 'Unknown database error',
      };
    }
  }

  private async checkRedis() {
    try {
      await this.redis.ping();
      return {
        status: 'up' as const,
        host: this.config.get<string>('redis.host'),
        port: this.config.get<number>('redis.port'),
      };
    } catch (error) {
      return {
        status: 'down' as const,
        message: error instanceof Error ? error.message : 'Unknown redis error',
      };
    }
  }
}
