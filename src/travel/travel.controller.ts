import { Body, Controller, Get, Param, Patch, Post, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { Roles } from '../access/roles.decorator';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { TravelService } from './travel.service';
import { BookTravelDto, PayTravelDto, TravelPackageQueryDto } from './dto/travel.dto';

@ApiTags('travel')
@Controller('travel')
export class TravelController {
  constructor(private readonly travel: TravelService) {}

  @Get('catalog')
  @ApiOperation({ summary: 'KarnaTravel categories from admin settings' })
  catalog() {
    return this.travel.catalog();
  }

  @Get('packages')
  @ApiOperation({ summary: 'Published travel packages (filter by category, destination, date)' })
  packages(@Query() query: TravelPackageQueryDto) {
    return this.travel.listPublished(query);
  }

  @Get('packages/:id')
  @ApiOperation({ summary: 'One published package' })
  packageOne(@Param('id') id: string) {
    return this.travel.onePublished(Number(id));
  }

  @Post('bookings')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Book a published package; fare is the admin package price' })
  book(@CurrentActor() actor: Actor, @Body() dto: BookTravelDto) {
    return this.travel.book(actor, dto);
  }

  @Get('bookings')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'List KarnaTravel bookings in caller scope' })
  listBookings(@CurrentActor() actor: Actor) {
    return this.travel.listBookings(actor);
  }

  @Get('bookings/:id')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @ApiOperation({ summary: 'One travel booking if allowed' })
  oneBooking(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.travel.oneBooking(actor, BigInt(id));
  }

  @Post('bookings/:id/pay')
  @ApiBearerAuth()
  @UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
  @Roles(UserRole.CUSTOMER, UserRole.CORPORATE)
  @ApiOperation({ summary: 'Pay and confirm a travel booking' })
  pay(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PayTravelDto) {
    return this.travel.pay(actor, BigInt(id), dto);
  }
}
