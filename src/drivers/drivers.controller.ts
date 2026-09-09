import { Body, Controller, Get, Param, Patch, Post, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions, Roles } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { BookingsService } from '../bookings/bookings.service';
import { ParcelsService } from '../parcels/parcels.service';
import { DriverOnlineDto } from './dto/driver-online.dto';
import { DriverDutyDto } from './dto/driver-duty.dto';
import { DriversService } from './drivers.service';
import { DriverDashboardService } from './driver-dashboard.service';
import { DriverCareService } from './driver-care.service';
import { LocationService } from '../location/location.service';
import { DriverLocationDto } from '../location/dto/driver-location.dto';
import { DriverWithdrawDto } from './dto/driver-withdraw.dto';
import { DriverSosDto, DriverSupportTicketDto } from './dto/driver-care.dto';
import { WalletsService } from '../wallets/wallets.service';

@ApiTags('drivers')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Controller('drivers')
export class DriversController {
  constructor(
    private readonly drivers: DriversService,
    private readonly dashboard: DriverDashboardService,
    private readonly bookings: BookingsService,
    private readonly parcels: ParcelsService,
    private readonly locations: LocationService,
    private readonly wallets: WalletsService,
    private readonly care: DriverCareService,
  ) {}

  @Get()
  @RequirePermissions(Permission.DriversRead)
  @ApiOperation({ summary: 'Drivers in the caller\'s territory or fleet' })
  list(@CurrentActor() actor: Actor) {
    return this.drivers.list(actor);
  }

  @Get('me')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Current driver profile, duty status, and home dashboard' })
  me(@CurrentActor() actor: Actor) {
    return this.dashboard.summary(actor.userId);
  }

  @Get('me/dashboard')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Driver Home payload (backend source of truth)' })
  dashboardHome(@CurrentActor() actor: Actor) {
    return this.dashboard.summary(actor.userId);
  }

  @Get('me/trips')
  @Roles(UserRole.DRIVER)
  trips(@CurrentActor() actor: Actor) {
    return this.dashboard.trips(actor.userId);
  }

  @Get('me/documents')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Document center with 30/15/7 day and expired alerts' })
  documents(@CurrentActor() actor: Actor) {
    return this.care.documents(actor.userId);
  }

  @Get('me/incentives')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Incentive targets, progress, validity, and earned bonus' })
  incentives(@CurrentActor() actor: Actor) {
    return this.care.incentives(actor.userId);
  }

  @Get('me/ratings')
  @Roles(UserRole.DRIVER)
  ratings(@CurrentActor() actor: Actor) {
    return this.care.ratings(actor.userId);
  }

  @Get('me/support')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'FAQ, call/chat, and existing tickets' })
  support(@CurrentActor() actor: Actor) {
    return this.care.support(actor.userId);
  }

  @Post('me/support/tickets')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Open a driver support ticket, optionally linked to a booking' })
  ticket(@CurrentActor() actor: Actor, @Body() dto: DriverSupportTicketDto) {
    return this.care.createTicket(actor.userId, dto);
  }

  @Get('me/sos')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'SOS numbers, emergency contact, live trip share payload' })
  sosGet(@CurrentActor() actor: Actor) {
    return this.care.sos(actor);
  }

  @Post('me/sos')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Log an SOS action and return call/share links' })
  sosPost(@CurrentActor() actor: Actor, @Body() dto: DriverSosDto) {
    return this.care.sos(actor, dto);
  }

  @Get('me/earnings')
  @Roles(UserRole.DRIVER)
  earnings(@CurrentActor() actor: Actor) {
    return this.dashboard.earnings(actor.userId);
  }

  @Post('me/wallet/withdraw')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Request a withdrawal. Debits wallet only through an immutable ledger row.' })
  withdraw(@CurrentActor() actor: Actor, @Body() dto: DriverWithdrawDto) {
    return this.wallets.requestDriverWithdrawal(actor.userId, dto.amountRupees);
  }

  @Patch('me/online')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Go online or offline for ride offers' })
  online(@CurrentActor() actor: Actor, @Body() dto: DriverOnlineDto) {
    return this.dashboard.setDuty(actor.userId, dto.online ? 'online' : 'offline');
  }

  @Patch('me/duty')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Set duty: online, offline, or busy' })
  duty(@CurrentActor() actor: Actor, @Body() dto: DriverDutyDto) {
    return this.dashboard.setDuty(actor.userId, dto.status);
  }

  @Post('me/location')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Publish driver GPS (Redis realtime + last known persist)' })
  pingLocation(@CurrentActor() actor: Actor, @Body() dto: DriverLocationDto) {
    return this.locations.ingest(actor, dto);
  }

  @Get('me/location')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Last known self location' })
  myLocation(@CurrentActor() actor: Actor) {
    return this.locations.mine(actor);
  }

  @Get('offers')
  @Roles(UserRole.DRIVER)
  @ApiOperation({ summary: 'Pending ride and permitted parcel requests for online drivers' })
  async offers(@CurrentActor() actor: Actor) {
    const [rides, parcels] = await Promise.all([
      this.bookings.offersForDriver(actor),
      this.parcels.offersForDriver(actor),
    ]);
    return { offers: [...rides.offers, ...parcels.offers] };
  }

  @Get(':id')
  @RequirePermissions(Permission.DriversRead)
  @ApiOperation({ summary: 'One driver if inside authorization scope' })
  one(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.drivers.one(actor, BigInt(id));
  }
}
