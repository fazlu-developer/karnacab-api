import { Body, Controller, Get, Param, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { ParcelsService } from './parcels.service';
import { CreateParcelDto, ParcelLifecycleDto, ParcelQuoteDto, PayParcelDto } from './dto/parcel.dto';

@ApiTags('parcels')
@Controller('parcels')
export class ParcelsController {
  constructor(private readonly parcels: ParcelsService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'Parcel lanes, types, prohibited goods, and fare vehicles' })
  catalog() {
    return this.parcels.catalog();
  }

  @Post('quote')
  @ApiOperation({ summary: 'Server parcel fare from parcel_fare_rules' })
  quote(@Body() dto: ParcelQuoteDto) {
    return this.parcels.quote(dto);
  }

  @Post()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Create a local or Bihar parcel after compliance confirmation' })
  create(@CurrentActor() actor: Actor, @Body() dto: CreateParcelDto) {
    return this.parcels.create(actor, dto);
  }

  @Get()
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'List parcels in the caller scope' })
  list(@CurrentActor() actor: Actor) {
    return this.parcels.list(actor);
  }

  @Get(':id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'One parcel if the caller is allowed to see it' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.parcels.one(actor, BigInt(id));
  }

  @Post(':id/pay')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Pay a created parcel and confirm for dispatch' })
  pay(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PayParcelDto) {
    return this.parcels.pay(actor, BigInt(id), dto);
  }

  @Post(':id/accept')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Driver accepts a paid, compliant parcel' })
  accept(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.parcels.accept(actor, BigInt(id));
  }

  @Post(':id/reject')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Driver declines a parcel offer without assigning it' })
  reject(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.parcels.reject(actor, BigInt(id));
  }

  @Post(':id/lifecycle')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE, UserRole.DRIVER, UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Advance parcel tracking (pickup OTP / delivery OTP)' })
  lifecycle(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: ParcelLifecycleDto,
  ) {
    return this.parcels.transition(actor, BigInt(id), dto);
  }
}
