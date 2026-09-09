import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { KycModule } from '../kyc/kyc.module';
import { WalletsModule } from '../wallets/wallets.module';
import { LocationModule } from '../location/location.module';
import { FleetController } from './fleet.controller';
import { FleetService } from './fleet.service';

@Module({
  imports: [AuthModule, KycModule, WalletsModule, LocationModule],
  controllers: [FleetController],
  providers: [FleetService],
  exports: [FleetService],
})
export class FleetModule {}
