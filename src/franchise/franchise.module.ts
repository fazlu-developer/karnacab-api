import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { KycModule } from '../kyc/kyc.module';
import { FranchiseController } from './franchise.controller';
import { FranchiseOpsController } from './franchise-ops.controller';
import { FranchiseService } from './franchise.service';

@Module({
  imports: [AuthModule, KycModule],
  controllers: [FranchiseController, FranchiseOpsController],
  providers: [FranchiseService],
  exports: [FranchiseService],
})
export class FranchiseModule {}
