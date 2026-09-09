import {
  Body,
  Controller,
  Get,
  Param,
  Post,
  UseGuards,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { BookingsService } from './bookings.service';
import { CreateBookingDto } from './dto/create-booking.dto';
import { BookingLifecycleDto } from './dto/booking-lifecycle.dto';
import { RateBookingDto } from './dto/rate-booking.dto';
import { RescheduleBookingDto } from './dto/reschedule-booking.dto';

@ApiTags('bookings')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Controller('bookings')
export class BookingsController {
  constructor(private readonly bookings: BookingsService) {}

  @Post()
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Create a ride booking request and notify online drivers' })
  create(@CurrentActor() actor: Actor, @Body() dto: CreateBookingDto) {
    return this.bookings.create(actor, dto);
  }

  @Get()
  @ApiOperation({ summary: 'List bookings in the caller\'s authorization scope' })
  list(@CurrentActor() actor: Actor) {
    return this.bookings.list(actor);
  }

  @Get(':id/live')
  @ApiOperation({ summary: 'Authorized live driver location for an active trip' })
  live(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.bookings.live(actor, BigInt(id));
  }

  @Get(':id')
  @ApiOperation({ summary: 'Booking status if the caller is allowed to see it' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.bookings.one(actor, BigInt(id));
  }

  @Post(':id/accept')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Driver accepts a requested booking' })
  accept(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.bookings.accept(actor.userId, BigInt(id));
  }

  @Post(':id/reject')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Driver declines a requested booking' })
  reject(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.bookings.reject(actor.userId, BigInt(id));
  }

  @Post(':id/reschedule')
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE, UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Move a scheduled ride to a new future pickup time' })
  reschedule(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: RescheduleBookingDto,
  ) {
    return this.bookings.reschedule(actor, BigInt(id), dto.scheduledAt);
  }

  @Post(':id/rate')
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE, UserRole.DRIVER)
  @ApiOperation({ summary: 'Rate a completed trip' })
  rate(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: RateBookingDto,
  ) {
    return this.bookings.rate(actor, BigInt(id), dto);
  }

  @Post(':id/lifecycle')
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE, UserRole.DRIVER, UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Advance the shared ride lifecycle (all ride types)' })
  lifecycle(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: BookingLifecycleDto,
  ) {
    return this.bookings.transition(actor, BigInt(id), dto);
  }
}
