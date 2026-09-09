import {
  Body,
  Controller,
  Delete,
  Get,
  Param,
  Patch,
  Post,
  Res,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { SafetyService } from './safety.service';
import type { FastifyReply } from 'fastify';
import {
  EmergencyContactDto,
  ReviewIncidentDto,
  SafetyShareDto,
  SafetySosDto,
  SafetyTicketDto,
} from './dto/safety.dto';

@ApiTags('safety')
@Controller('safety')
export class SafetyController {
  constructor(private readonly safety: SafetyService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Customer and driver safety tools, incident fields, statuses' })
  catalog() {
    return this.safety.catalog();
  }

  @Get('share/:token')
  @ApiOperation({ summary: 'Public live trip share. First names and plate last-4 only. No OTP or phones.' })
  publicShare(@Param('token') token: string) {
    return this.safety.publicShare(token);
  }

  @Get('me')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  me(@CurrentActor() actor: Actor) {
    return this.safety.me(actor);
  }

  @Get('contacts')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  contacts(@CurrentActor() actor: Actor) {
    return this.safety.contacts(actor);
  }

  @Delete('contacts/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  removeContact(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.safety.removeContact(actor, BigInt(id));
  }

  @Patch('emergency-contact')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  emergency(@CurrentActor() actor: Actor, @Body() dto: EmergencyContactDto) {
    return this.safety.saveEmergency(actor, dto);
  }

  @Get('sos')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'SOS numbers, emergency contact, live trip share. Does not create an incident.' })
  sosGet(@CurrentActor() actor: Actor) {
    return this.safety.sos(actor);
  }

  @Post('sos')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Log SOS (police/emergency/karnacab) as an incident. kind=share only refreshes the trip link.' })
  sosPost(@CurrentActor() actor: Actor, @Body() dto: SafetySosDto) {
    return this.safety.sos(actor, dto);
  }

  @Post('share')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Issue or refresh a live trip share token' })
  share(@CurrentActor() actor: Actor, @Body() dto: SafetyShareDto) {
    return this.safety.share(actor, dto);
  }

  @Get('bookings/:id/verify')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Driver/vehicle verification and customer start/end OTP. Driver never receives OTP.' })
  verify(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.safety.verify(actor, BigInt(id));
  }

  @Get('bookings/:id/driver-photo')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Driver photo for a booking the caller is on. Not included on share links.' })
  async driverPhoto(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.safety.driverPhoto(actor, BigInt(id));
    reply.header('Content-Type', file.mime);
    reply.header('Content-Disposition', `inline; filename="${file.name}"`);
    reply.header('Cache-Control', 'private, no-store');
    return reply.send(file.buffer);
  }

  @Post('tickets')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Complaint, lost & found, or support ticket (creates an incident)' })
  ticket(@CurrentActor() actor: Actor, @Body() dto: SafetyTicketDto) {
    return this.safety.createTicket(actor, dto);
  }

  @Get('incidents')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Own incidents, or territory incidents for support/admin' })
  list(@CurrentActor() actor: Actor) {
    return this.safety.listIncidents(actor);
  }

  @Get('incidents/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.safety.oneIncident(actor, BigInt(id));
  }

  @Patch('incidents/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.SafetyWrite)
  @ApiOperation({ summary: 'Admin/support investigation: status and internal note' })
  review(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: ReviewIncidentDto) {
    return this.safety.review(actor, BigInt(id), dto);
  }
}
