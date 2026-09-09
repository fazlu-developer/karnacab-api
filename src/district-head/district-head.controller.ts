import { Controller, Get, Param, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { DistrictHeadService } from './district-head.service';

@ApiTags('district-head')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(UserRole.DISTRICT_HEAD, UserRole.FRANCHISE, UserRole.ADMIN, UserRole.SUPER_ADMIN)
@Controller('district')
export class DistrictHeadController {
  constructor(private readonly districtHead: DistrictHeadService) {}

  @Get()
  @ApiOperation({ summary: 'District Head / exclusive franchise dashboard for the assigned district only' })
  dashboard(@CurrentActor() actor: Actor) {
    return this.districtHead.dashboard(actor);
  }

  @Get('fleet')
  fleet(@CurrentActor() actor: Actor) {
    return this.districtHead.fleet(actor);
  }

  @Get('fleet/:id')
  fleetOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.fleetOne(actor, BigInt(id));
  }

  @Get('drivers')
  drivers(@CurrentActor() actor: Actor) {
    return this.districtHead.drivers(actor);
  }

  @Get('drivers/:id')
  driver(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.driver(actor, BigInt(id));
  }

  @Get('vehicles')
  vehicles(@CurrentActor() actor: Actor) {
    return this.districtHead.vehiclesList(actor);
  }

  @Get('vehicles/:id')
  vehicle(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.vehicle(actor, BigInt(id));
  }

  @Get('bookings')
  @ApiOperation({ summary: 'Local ride bookings in the assigned district' })
  bookings(@CurrentActor() actor: Actor) {
    return this.districtHead.bookings(actor);
  }

  @Get('bookings/:id')
  booking(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.booking(actor, BigInt(id));
  }

  @Get('bulk')
  @ApiOperation({ summary: 'Bulk bookings whose customers sit in the assigned district' })
  bulk(@CurrentActor() actor: Actor) {
    return this.districtHead.bulk(actor);
  }

  @Get('bulk/:id')
  bulkOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.bulkOne(actor, BigInt(id));
  }

  @Get('parcels')
  parcels(@CurrentActor() actor: Actor) {
    return this.districtHead.parcels(actor);
  }

  @Get('parcels/:id')
  parcel(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.parcel(actor, BigInt(id));
  }

  @Get('revenue')
  revenue(@CurrentActor() actor: Actor) {
    return this.districtHead.revenue(actor);
  }

  @Get('commission')
  commission(@CurrentActor() actor: Actor) {
    return this.districtHead.commission(actor);
  }

  @Get('wallet')
  wallet(@CurrentActor() actor: Actor) {
    return this.districtHead.wallet(actor);
  }

  @Get('wallet/:id')
  walletOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.walletOne(actor, BigInt(id));
  }

  @Get('reports')
  reports(@CurrentActor() actor: Actor) {
    return this.districtHead.reports(actor);
  }

  @Get('complaints')
  complaints(@CurrentActor() actor: Actor) {
    return this.districtHead.complaints(actor);
  }

  @Get('complaints/:id')
  complaint(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.districtHead.complaint(actor, BigInt(id));
  }

  @Get('operations')
  operations(@CurrentActor() actor: Actor) {
    return this.districtHead.operations(actor);
  }
}
