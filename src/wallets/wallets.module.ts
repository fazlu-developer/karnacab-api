import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { WalletsController } from './wallets.controller';
import { WalletsService } from './wallets.service';
import { WalletSettlementService } from './wallet-settlement.service';

@Module({
  imports: [AuthModule],
  controllers: [WalletsController],
  providers: [WalletsService, WalletSettlementService],
  exports: [WalletsService, WalletSettlementService],
})
export class WalletsModule {}
