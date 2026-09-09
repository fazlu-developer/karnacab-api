import { Module } from '@nestjs/common';
import { GoogleMapsService } from './google-maps.service';
import { PlacesController } from './places.controller';
import { PlacesService } from './places.service';

@Module({
  controllers: [PlacesController],
  providers: [PlacesService, GoogleMapsService],
  exports: [PlacesService, GoogleMapsService],
})
export class PlacesModule {}
