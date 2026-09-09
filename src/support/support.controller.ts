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
import type { FastifyReply } from 'fastify';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { SupportService } from './support.service';
import {
  AssignTicketDto,
  CreateSupportTicketDto,
  ResolveTicketDto,
  SupportAttachmentDto,
  SupportMessageDto,
} from './dto/support.dto';

@ApiTags('support')
@Controller('support')
export class SupportController {
  constructor(private readonly support: SupportService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Ticket kinds, statuses, and attachment rules' })
  catalog() {
    return this.support.catalog();
  }

  @Get('faqs')
  @ApiOperation({ summary: 'Help articles by audience (customer, driver, ops)' })
  faqs(@Query('audience') audience?: string) {
    return this.support.faqs(audience || 'customer');
  }

  @Get('tickets')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  mine(@CurrentActor() actor: Actor) {
    return this.support.listMine(actor);
  }

  @Post('tickets')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  @ApiOperation({ summary: 'Open a ticket. kind=complaint with bookingId also creates a safety incident.' })
  create(@CurrentActor() actor: Actor, @Body() dto: CreateSupportTicketDto) {
    return this.support.create(actor, dto);
  }

  @Get('queue')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.SafetyWrite)
  @ApiOperation({ summary: 'Open tickets in territory for operations' })
  queue(
    @CurrentActor() actor: Actor,
    @Query('status') status?: string,
    @Query('kind') kind?: string,
  ) {
    return this.support.queue(actor, status, kind);
  }

  @Get('tickets/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.support.one(actor, BigInt(id));
  }

  @Post('tickets/:id/messages')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  message(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: SupportMessageDto) {
    return this.support.addMessage(actor, BigInt(id), dto.body, false);
  }

  @Post('tickets/:id/replies')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.SafetyWrite)
  reply(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: SupportMessageDto) {
    return this.support.addMessage(actor, BigInt(id), dto.body, true);
  }

  @Post('tickets/:id/assign')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.SafetyWrite)
  @ApiOperation({ summary: 'Assign an agent; Open moves to Assigned' })
  assign(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: AssignTicketDto) {
    return this.support.assign(actor, BigInt(id), dto.agentId);
  }

  @Patch('tickets/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @RequirePermissions(Permission.SafetyWrite)
  @ApiOperation({ summary: 'Advance ticket status. Flow: Open → Assigned → In Progress → Resolved → Closed' })
  resolve(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: ResolveTicketDto) {
    return this.support.resolve(actor, BigInt(id), dto);
  }

  @Post('tickets/:id/attachments')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  attach(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: SupportAttachmentDto) {
    return this.support.attach(actor, BigInt(id), dto);
  }

  @Get('tickets/:id/attachments/:attachmentId')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard)
  async file(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Param('attachmentId') attachmentId: string,
    @Res() reply: FastifyReply,
  ) {
    const file = await this.support.attachmentFile(actor, BigInt(id), BigInt(attachmentId));
    return reply.header('content-disposition', `inline; filename="${file.name}"`).type(file.mime).send(file.bytes);
  }
}
