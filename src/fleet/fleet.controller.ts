import {
  Body,
  Controller,
  Get,
  Param,
  Patch,
  Post,
  Query,
  Res,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import type { FastifyReply } from 'fastify';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { FleetService } from './fleet.service';
import {
  AssignFleetDriverDto,
  CreateFleetDriverDto,
  CreateFleetVehicleDto,
  PatchFleetVehicleDto,
  UnassignFleetDriverDto,
  UploadFleetVehicleDocumentDto,
} from './dto/fleet.dto';
import { FLEET_VEHICLE_STATUS_LABELS, VEHICLE_DOC_LABELS } from './fleet-vehicle-status';
import { LocationService } from '../location/location.service';
import { FleetMapQueryDto } from '../location/dto/fleet-map-query.dto';
import { FLEET_MAP_STATUS_LABELS } from '../location/fleet-map-status';

@ApiTags('fleet')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(UserRole.FLEET_OWNER)
@Controller('fleet')
export class FleetController {
  constructor(
    private readonly fleet: FleetService,
    private readonly locations: LocationService,
  ) {}

  @Get()
  @ApiOperation({ summary: 'Fleet home: vehicles, drivers, today trips (owned fleet only)' })
  overview(@CurrentActor() actor: Actor) {
    return this.fleet.overview(actor);
  }

  @Get('catalog')
  catalog() {
    return {
      vehicleStatuses: FLEET_VEHICLE_STATUS_LABELS,
      mapStatuses: FLEET_MAP_STATUS_LABELS,
      vehicleDocumentTypes: VEHICLE_DOC_LABELS,
    };
  }

  @Get('map')
  @ApiOperation({ summary: 'Live map of owned fleet vehicles only (not other fleets)' })
  map(@CurrentActor() actor: Actor, @Query() query: FleetMapQueryDto) {
    return this.locations.liveFleetMap(actor, query.status);
  }

  @Get('vehicles')
  vehicles(@CurrentActor() actor: Actor) {
    return this.fleet.vehicles(actor);
  }

  @Post('vehicles')
  @ApiOperation({ summary: 'Add a vehicle to the owned fleet' })
  addVehicle(@CurrentActor() actor: Actor, @Body() dto: CreateFleetVehicleDto) {
    return this.fleet.addVehicle(actor, dto);
  }

  @Get('vehicles/:id')
  vehicle(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.fleet.vehicle(actor, BigInt(id));
  }

  @Patch('vehicles/:id')
  patchVehicle(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PatchFleetVehicleDto) {
    return this.fleet.patchVehicle(actor, BigInt(id), dto);
  }

  @Post('vehicles/:id/documents')
  uploadDoc(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: UploadFleetVehicleDocumentDto,
  ) {
    return this.fleet.uploadVehicleDocument(actor, BigInt(id), dto);
  }

  @Get('vehicles/:vehicleId/documents/:documentId/file')
  async docFile(
    @CurrentActor() actor: Actor,
    @Param('vehicleId') vehicleId: string,
    @Param('documentId') documentId: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.fleet.vehicleDocumentFile(actor, BigInt(vehicleId), BigInt(documentId));
    reply.header('Content-Type', file.mime);
    reply.header('Content-Disposition', `inline; filename="${file.name}"`);
    reply.header('Cache-Control', 'private, no-store');
    return reply.send(file.buffer);
  }

  @Get('drivers')
  drivers(@CurrentActor() actor: Actor) {
    return this.fleet.drivers(actor);
  }

  @Post('drivers')
  @ApiOperation({ summary: 'Add a driver to the owned fleet' })
  addDriver(@CurrentActor() actor: Actor, @Body() dto: CreateFleetDriverDto) {
    return this.fleet.addDriver(actor, dto);
  }

  @Get('drivers/:id')
  driver(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.fleet.driver(actor, BigInt(id));
  }

  @Post('drivers/:id/assign')
  assign(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: AssignFleetDriverDto) {
    return this.fleet.assign(actor, BigInt(id), dto);
  }

  @Post('drivers/:id/unassign')
  unassign(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: UnassignFleetDriverDto) {
    return this.fleet.unassign(actor, BigInt(id), dto);
  }

  @Get('trips')
  trips(@CurrentActor() actor: Actor) {
    return this.fleet.trips(actor);
  }

  @Get('trips/:id')
  trip(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.fleet.trip(actor, BigInt(id));
  }

  @Get('earnings')
  earnings(@CurrentActor() actor: Actor) {
    return this.fleet.earnings(actor);
  }

  @Get('wallet')
  wallet(@CurrentActor() actor: Actor) {
    return this.fleet.wallet(actor);
  }

  @Get('commission')
  commission(@CurrentActor() actor: Actor) {
    return this.fleet.commission(actor);
  }

  @Get('reports')
  reports(@CurrentActor() actor: Actor) {
    return this.fleet.reports(actor);
  }
}
