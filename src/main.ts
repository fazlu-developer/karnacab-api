import { ValidationPipe, VersioningType } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import { Logger } from 'nestjs-pino';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({
      logger: false,
      bodyLimit: 10 * 1024 * 1024,
      trustProxy: true,
    }),
    { bufferLogs: true },
  );

  const config = app.get(ConfigService);
  const port =
    parseInt(String(process.env.PORT || ''), 10) ||
    config.get<number>('app.port') ||
    3000;
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

  await app.listen(port, '0.0.0.0');
}

(BigInt.prototype as unknown as { toJSON?: () => string }).toJSON = function toJSON() {
  return this.toString();
};

void bootstrap().catch((error) => {
  console.error('KarnaCab API failed to start');
  console.error(error);
  process.exit(1);
});
