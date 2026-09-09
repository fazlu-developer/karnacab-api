import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { CatalogModule } from '../catalog/catalog.module';
import { RideEngineModule } from '../ride-engine/ride-engine.module';
import { SupportModule } from '../support/support.module';
import { CmsController } from './cms.controller';
import { CmsService } from './cms.service';

@Module({
  imports: [AuthModule, CatalogModule, SupportModule, RideEngineModule],
  controllers: [CmsController],
  providers: [CmsService],
})
export class CmsModule {}
