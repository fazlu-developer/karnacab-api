import { HealthService } from './health.service';

describe('HealthService', () => {
  const prisma = { ping: jest.fn() };
  const redis = { ping: jest.fn() };
  const config = {
    get: jest.fn((key: string) => {
      const values: Record<string, string | number> = {
        'app.name': 'KarnaCab API',
        'app.version': '1',
        'app.prefix': 'api/v1',
        'database.host': 'localhost',
        'database.port': 3306,
        'database.name': 'karnacab_api',
        'redis.host': '127.0.0.1',
        'redis.port': 6379,
      };
      return values[key];
    }),
  };

  const service = new HealthService(
    prisma as never,
    redis as never,
    config as never,
  );

  it('reports ok when MySQL and Redis respond', async () => {
    prisma.ping.mockResolvedValue(true);
    redis.ping.mockResolvedValue(true);

    const result = await service.check();

    expect(result.status).toBe('ok');
    expect(result.checks.database.status).toBe('up');
    expect(result.checks.redis.status).toBe('up');
  });

  it('reports degraded when a dependency is down', async () => {
    prisma.ping.mockRejectedValue(new Error('connection refused'));
    redis.ping.mockResolvedValue(true);

    const result = await service.check();

    expect(result.status).toBe('degraded');
    expect(result.checks.database.status).toBe('down');
  });
});
