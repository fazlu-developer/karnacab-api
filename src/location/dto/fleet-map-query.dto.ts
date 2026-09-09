import { IsIn, IsOptional } from 'class-validator';
import { FLEET_MAP_STATUSES, FleetMapStatus } from '../fleet-map-status';

export class FleetMapQueryDto {
  @IsOptional()
  @IsIn([...FLEET_MAP_STATUSES])
  status?: FleetMapStatus;
}
