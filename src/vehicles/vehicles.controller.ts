import { Controller, Get, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { VehiclesService } from './vehicles.service';
import { LocationService } from '../location/location.service';

@ApiTags('vehicles')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Controller('vehicles')
export class VehiclesController {
  constructor(
    private readonly vehicles: VehiclesService,
    private readonly locations: LocationService,
  ) {}

  @Get()
  @RequirePermissions(Permission.VehiclesRead)
  @ApiOperation({ summary: 'Vehicles in the caller\'s fleet or territory' })
  list(@CurrentActor() actor: Actor) {
    return this.vehicles.list(actor);
  }

  @Get('me')
  @RequirePermissions(Permission.VehiclesRead)
  @ApiOperation({ summary: 'Vehicles for the authenticated driver or fleet owner' })
  mine(@CurrentActor() actor: Actor) {
    return this.vehicles.mine(actor.userId);
  }

  @Get('live')
  @RequirePermissions(Permission.VehiclesRead)
  @ApiOperation({ summary: 'Authorized live vehicle locations (fleet/territory)' })
  live(@CurrentActor() actor: Actor) {
    return this.locations.liveVehicles(actor);
  }

  @Get(':id')
  @RequirePermissions(Permission.VehiclesRead)
  @ApiOperation({ summary: 'One vehicle if inside authorization scope' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.vehicles.one(actor, BigInt(id));
  }
}
