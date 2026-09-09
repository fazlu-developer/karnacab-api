import { Module } from '@nestjs/common';
import { FareEngine } from './fare.engine';
import { RideEngineController } from './ride-engine.controller';

@Module({
  controllers: [RideEngineController],
  providers: [FareEngine],
  exports: [FareEngine],
})
export class RideEngineModule {}
