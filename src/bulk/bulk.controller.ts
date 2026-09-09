import { Body, Controller, Get, Param, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { BulkService } from './bulk.service';
import { BulkLifecycleDto, BulkQuoteDto, CreateBulkDto, PayBulkDto } from './dto/bulk.dto';

@ApiTags('bulk')
@Controller('bulk')
export class BulkController {
  constructor(private readonly bulk: BulkService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Bulk event types and vehicle rates' })
  catalog() {
    return this.bulk.catalog();
  }

  @Post('quote')
  @ApiOperation({ summary: 'Server bulk quotation from bulk_rate_rules' })
  quote(@Body() dto: BulkQuoteDto) {
    return this.bulk.quote(dto);
  }

  @Post()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Create a bulk booking request' })
  create(@CurrentActor() actor: Actor, @Body() dto: CreateBulkDto) {
    return this.bulk.create(actor, dto);
  }

  @Get()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'List bulk bookings in caller scope' })
  list(@CurrentActor() actor: Actor) {
    return this.bulk.list(actor);
  }

  @Get(':id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'One bulk booking if allowed' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.bulk.one(actor, BigInt(id));
  }

  @Post(':id/advance')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Pay advance after accepting quotation' })
  advance(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PayBulkDto) {
    return this.bulk.payAdvance(actor, BigInt(id), dto);
  }

  @Post(':id/settle')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Pay final invoice balance' })
  settle(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PayBulkDto) {
    return this.bulk.payBalance(actor, BigInt(id), dto);
  }

  @Post(':id/lifecycle')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(
    UserRole.CUSTOMER,
    UserRole.CORPORATE,
    UserRole.DISTRICT_HEAD,
    UserRole.ADMIN,
    UserRole.SUPER_ADMIN,
  )
  @ApiOperation({ summary: 'Accept, assign, start trip, or issue invoice' })
  lifecycle(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: BulkLifecycleDto) {
    return this.bulk.transition(actor, BigInt(id), dto);
  }
}
