import { Controller, Get, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { StateHeadService } from './state-head.service';

@ApiTags('state-head')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(UserRole.STATE_HEAD, UserRole.ADMIN, UserRole.SUPER_ADMIN)
@Controller('state')
export class StateHeadController {
  constructor(private readonly stateHead: StateHeadService) {}

  @Get()
  @ApiOperation({ summary: 'State Head dashboard for the assigned state only' })
  dashboard(@CurrentActor() actor: Actor) {
    return this.stateHead.dashboard(actor);
  }

  @Get('districts')
  districts(@CurrentActor() actor: Actor) {
    return this.stateHead.districts(actor);
  }

  @Get('districts/:id')
  district(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.district(actor, Number(id));
  }

  @Get('district-heads')
  districtHeads(@CurrentActor() actor: Actor) {
    return this.stateHead.districtHeads(actor);
  }

  @Get('fleet')
  fleet(@CurrentActor() actor: Actor) {
    return this.stateHead.fleet(actor);
  }

  @Get('fleet/:id')
  fleetOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.fleetOne(actor, BigInt(id));
  }

  @Get('drivers')
  drivers(@CurrentActor() actor: Actor) {
    return this.stateHead.drivers(actor);
  }

  @Get('drivers/:id')
  driver(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.driver(actor, BigInt(id));
  }

  @Get('vehicles')
  vehicles(@CurrentActor() actor: Actor) {
    return this.stateHead.vehiclesList(actor);
  }

  @Get('vehicles/:id')
  vehicle(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.vehicle(actor, BigInt(id));
  }

  @Get('bookings')
  bookings(@CurrentActor() actor: Actor) {
    return this.stateHead.bookings(actor);
  }

  @Get('bookings/:id')
  booking(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.booking(actor, BigInt(id));
  }

  @Get('parcels')
  parcels(@CurrentActor() actor: Actor) {
    return this.stateHead.parcels(actor);
  }

  @Get('parcels/:id')
  parcel(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.parcel(actor, BigInt(id));
  }

  @Get('revenue')
  revenue(@CurrentActor() actor: Actor) {
    return this.stateHead.revenue(actor);
  }

  @Get('commission')
  commission(@CurrentActor() actor: Actor) {
    return this.stateHead.commission(actor);
  }

  @Get('reports')
  reports(@CurrentActor() actor: Actor) {
    return this.stateHead.reports(actor);
  }

  @Get('complaints')
  complaints(@CurrentActor() actor: Actor) {
    return this.stateHead.complaints(actor);
  }

  @Get('complaints/:id')
  complaint(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.stateHead.complaint(actor, BigInt(id));
  }

  @Get('operations')
  operations(@CurrentActor() actor: Actor) {
    return this.stateHead.operations(actor);
  }
}
