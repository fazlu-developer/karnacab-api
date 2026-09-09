import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { LoggerModule } from 'nestjs-pino';
import configuration from './config/configuration';
import { validateEnv } from './config/env.validation';
import { AccessModule } from './access/access.module';
import { AuthModule } from './auth/auth.module';
import { BookingsModule } from './bookings/bookings.module';
import { CatalogModule } from './catalog/catalog.module';
import { CommonModule } from './common/common.module';
import { DriversModule } from './drivers/drivers.module';
import { HealthModule } from './health/health.module';
import { LeadsModule } from './leads/leads.module';
import { PlacesModule } from './places/places.module';
import { PlatformModule } from './platform/platform.module';
import { PrismaModule } from './prisma/prisma.module';
import { QuotesModule } from './quotes/quotes.module';
import { RideEngineModule } from './ride-engine/ride-engine.module';
import { RedisModule } from './redis/redis.module';
import { VehiclesModule } from './vehicles/vehicles.module';
import { WalletsModule } from './wallets/wallets.module';
import { PaymentsModule } from './payments/payments.module';
import { AdsModule } from './ads/ads.module';
import { SafetyModule } from './safety/safety.module';
import { ExperienceModule } from './experience/experience.module';
import { NotifyModule } from './notify/notify.module';
import { SupportModule } from './support/support.module';
import { OpsModule } from './ops/ops.module';
import { ParcelsModule } from './parcels/parcels.module';
import { TravelModule } from './travel/travel.module';
import { BulkModule } from './bulk/bulk.module';
import { CorporateModule } from './corporate/corporate.module';
import { KycModule } from './kyc/kyc.module';
import { LocationModule } from './location/location.module';
import { FleetModule } from './fleet/fleet.module';
import { FranchiseModule } from './franchise/franchise.module';
import { StateHeadModule } from './state-head/state-head.module';
import { DistrictHeadModule } from './district-head/district-head.module';
import { CmsModule } from './cms/cms.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      cache: true,
      expandVariables: true,
      envFilePath: ['.env', '.env.local'],
      load: [configuration],
      validate: validateEnv,
    }),
    LoggerModule.forRoot({
      pinoHttp: {
        level: process.env.LOG_LEVEL ?? 'info',
        transport:
          process.env.NODE_ENV !== 'production'
            ? {
                target: 'pino-pretty',
                options: {
                  colorize: true,
                  singleLine: true,
                  translateTime: 'SYS:standard',
                },
              }
            : undefined,
      },
    }),
    PrismaModule,
    RedisModule,
    CommonModule,
    AccessModule,
    HealthModule,
    AuthModule,
    CatalogModule,
    QuotesModule,
    RideEngineModule,
    BookingsModule,
    LeadsModule,
    PlacesModule,
    DriversModule,
    KycModule,
    LocationModule,
    PlatformModule,
    WalletsModule,
    PaymentsModule,
    AdsModule,
    SafetyModule,
    NotifyModule,
    SupportModule,
    ExperienceModule,
    VehiclesModule,
    OpsModule,
    ParcelsModule,
    TravelModule,
    BulkModule,
    CorporateModule,
    FleetModule,
    FranchiseModule,
    StateHeadModule,
    DistrictHeadModule,
    CmsModule,
  ],
})
export class AppModule {}
