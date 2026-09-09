import { Module } from '@nestjs/common';
import { DemoDriverService } from '../drivers/demo-driver.service';
import { RideEngineModule } from '../ride-engine/ride-engine.module';
import { PlacesModule } from '../places/places.module';
import { QuotesController } from './quotes.controller';
import { QuotesService } from './quotes.service';

@Module({
  imports: [RideEngineModule, PlacesModule],
  controllers: [QuotesController],
  providers: [QuotesService, DemoDriverService],
  exports: [QuotesService, DemoDriverService],
})
export class QuotesModule {}
