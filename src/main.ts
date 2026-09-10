import './hosting-early-bind';
import { ValidationPipe, VersioningType } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import type { IncomingMessage, Server, ServerResponse } from 'http';
import { Logger } from 'nestjs-pino';
import { AppModule } from './app.module';

type HostingGlobals = typeof globalThis & {
  __karnacabHttpServer?: Server;
  __karnacabSetError?: (error: unknown) => void;
};

function reuseHostingServer(existing: Server) {
  return (handler: (req: IncomingMessage, res: ServerResponse) => void) => {
    existing.removeAllListeners('request');
    existing.on('request', handler);
    (existing as Server & { listen: (...args: unknown[]) => Server }).listen = (
      ...args: unknown[]
    ) => {
      const callback = args.find((arg) => typeof arg === 'function') as
        | (() => void)
        | undefined;
      if (callback) {
        process.nextTick(callback);
      }
      return existing;
    };
    return existing;
  };
}

async function bootstrap() {
  const hosting = globalThis as HostingGlobals;
  const existingServer = hosting.__karnacabHttpServer;

  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({
      logger: false,
      bodyLimit: 10 * 1024 * 1024,
      trustProxy: true,
      ...(existingServer
        ? { serverFactory: reuseHostingServer(existingServer) }
        : {}),
    }),
    { bufferLogs: true },
  );

  const config = app.get(ConfigService);
  const port =
    parseInt(String(process.env.PORT || ''), 10) ||
    config.get<number>('app.port') ||
    3000;
  const host = process.env.HOST || '0.0.0.0';
  const origins = config.get<string[]>('cors.origins') ?? [];

  app.useLogger(app.get(Logger));
  app.setGlobalPrefix('api');
  app.enableVersioning({
    type: VersioningType.URI,
    defaultVersion: '1',
  });

  app.enableCors({
    origin: origins,
    credentials: true,
  });

  app.useGlobalPipes(
    new ValidationPipe({
      whitelist: true,
      forbidNonWhitelisted: true,
      transform: true,
      transformOptions: {
        enableImplicitConversion: true,
      },
    }),
  );

  const swagger = new DocumentBuilder()
    .setTitle('KarnaCab API')
    .setDescription('KarnaCab mobility API — auth, roles, quotes, bookings, wallets, vehicles.')
    .setVersion('1.0')
    .addBearerAuth()
    .build();

  const document = SwaggerModule.createDocument(app, swagger);
  SwaggerModule.setup('docs', app, document, {
    useGlobalPrefix: true,
  });

  const adapter = app.getHttpAdapter();
  adapter.get('/', (_req: unknown, reply: { send: (body: unknown) => void }) => {
    reply.send({
      ok: true,
      service: 'karnacab-api',
      health: '/api/v1/health',
      docs: '/api/docs',
    });
  });

  await app.listen(port, host);
}

(BigInt.prototype as unknown as { toJSON?: () => string }).toJSON = function toJSON() {
  return this.toString();
};

void bootstrap().catch((error) => {
  console.error('KarnaCab API failed to start');
  console.error(error);
  const hosting = globalThis as HostingGlobals;
  if (hosting.__karnacabSetError) {
    hosting.__karnacabSetError(error);
    return;
  }
  process.exit(1);
});
