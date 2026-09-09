import { Body, Controller, Get, NotFoundException, Param, Patch, Post, Query, UseGuards } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { UserRole } from '@prisma/client';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { AccessContextGuard } from '../access/access-context.guard';
import { RolesGuard } from '../access/roles.guard';
import { RequirePermissions, Roles } from '../access/roles.decorator';
import { Permission } from '../access/permissions';
import { CurrentActor } from '../access/current-actor.decorator';
import { Actor } from '../access/territory';
import { OpsService } from './ops.service';
import { UpdateFareRuleDto } from './dto/update-fare-rule.dto';
import { ReviewWithdrawalDto, UpdateCommissionRuleDto } from './dto/update-commission-rule.dto';
import { BookingsService } from '../bookings/bookings.service';
import { DriversService } from '../drivers/drivers.service';
import { VehiclesService } from '../vehicles/vehicles.service';
import { ScopeService } from '../access/scope.service';
import { PrismaService } from '../prisma/prisma.service';
import { TravelService } from '../travel/travel.service';
import {
  PatchTravelPackageDto,
  UpsertTravelPackageDto,
} from '../travel/dto/travel.dto';
import { BulkService } from '../bulk/bulk.service';
import { BulkLifecycleDto } from '../bulk/dto/bulk.dto';
import { LocationService } from '../location/location.service';
import { WalletsService } from '../wallets/wallets.service';
import { FleetMapQueryDto } from '../location/dto/fleet-map-query.dto';
import { LIVE_FLEET_MAP_ROLES } from '../location/location-access';
import { OpsAdminService } from './ops-admin.service';
import {
  CreateOpsUserDto,
  OpsBookingQueryDto,
  OpsUserQueryDto,
  PatchOpsDriverDto,
  PatchOpsUserDto,
  PatchOpsUserStatusDto,
  PatchSettingDto,
  UpsertCouponDto,
} from './dto/ops-admin.dto';

const OPS = [
  UserRole.FLEET_OWNER,
  UserRole.DISTRICT_HEAD,
  UserRole.STATE_HEAD,
  UserRole.FRANCHISE,
  UserRole.CORPORATE,
  UserRole.ADMIN,
  UserRole.SUPER_ADMIN,
] as const;

@ApiTags('ops')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard, AccessContextGuard, RolesGuard)
@Roles(...OPS)
@Controller('ops')
export class OpsController {
  constructor(
    private readonly ops: OpsService,
    private readonly bookings: BookingsService,
    private readonly drivers: DriversService,
    private readonly vehicles: VehiclesService,
    private readonly scopes: ScopeService,
    private readonly travel: TravelService,
    private readonly bulk: BulkService,
    private readonly prisma: PrismaService,
    private readonly locations: LocationService,
    private readonly wallets: WalletsService,
    private readonly admin: OpsAdminService,
  ) {}

  @Get('dashboard')
  @ApiOperation({ summary: 'Super-admin / operator KPI dashboard for the authorized territory' })
  dashboard(@CurrentActor() actor: Actor) {
    return this.admin.dashboard(actor);
  }

  @Get('users')
  @RequirePermissions(Permission.UsersRead)
  @ApiOperation({ summary: 'Users in territory. Password hashes and bank secrets are never returned.' })
  users(@CurrentActor() actor: Actor, @Query() query: OpsUserQueryDto) {
    return this.admin.listUsers(actor, query);
  }

  @Post('users')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN, UserRole.STATE_HEAD, UserRole.DISTRICT_HEAD)
  @RequirePermissions(Permission.UsersWrite)
  createUser(@CurrentActor() actor: Actor, @Body() dto: CreateOpsUserDto) {
    return this.admin.createUser(actor, dto);
  }

  @Get('users/:id')
  @RequirePermissions(Permission.UsersRead)
  oneUser(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.admin.getUser(actor, BigInt(id));
  }

  @Patch('users/:id')
  @RequirePermissions(Permission.UsersWrite)
  patchUser(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PatchOpsUserDto) {
    return this.admin.patchUser(actor, BigInt(id), dto);
  }

  @Patch('users/:id/status')
  @RequirePermissions(Permission.UsersWrite)
  @ApiOperation({ summary: 'Activate, deactivate (PENDING), or block (SUSPENDED) a user' })
  patchUserStatus(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: PatchOpsUserStatusDto,
  ) {
    return this.admin.patchUserStatus(actor, BigInt(id), dto.status);
  }

  @Get('bookings')
  @ApiOperation({ summary: 'Search bookings by ref, people, vehicle, product, territory, dates, payment' })
  bookingsList(@CurrentActor() actor: Actor, @Query() query: OpsBookingQueryDto) {
    return this.admin.searchBookings(actor, query);
  }

  @Get('bookings/:id')
  @ApiOperation({ summary: 'Booking lifecycle track and status log' })
  bookingOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.admin.bookingDetail(actor, BigInt(id));
  }

  @Get('drivers')
  @ApiOperation({ summary: 'Drivers inside the operator territory or fleet' })
  driversList(@CurrentActor() actor: Actor) {
    return this.drivers.list(actor);
  }

  @Get('drivers/:id')
  driverOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    return this.drivers.one(actor, BigInt(id));
  }

  @Patch('drivers/:id')
  @RequirePermissions(Permission.DriversWrite)
  @ApiOperation({ summary: 'Approve, reject, suspend, activate, assign fleet or vehicle' })
  patchDriver(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: PatchOpsDriverDto) {
    return this.admin.patchDriver(actor, BigInt(id), dto);
  }

  @Get('live-vehicles')
  @ApiOperation({ summary: 'Live vehicles in the operator territory or fleet' })
  liveVehicles(@CurrentActor() actor: Actor) {
    return this.locations.liveVehicles(actor);
  }

  @Get('fleet-map')
  @Roles(...LIVE_FLEET_MAP_ROLES)
  @ApiOperation({
    summary:
      'Authorized live fleet map. Admin = all; state head = assigned state; district head = assigned district only; fleet owner = own fleet. Scope is enforced in the query, not the client.',
  })
  fleetMap(@CurrentActor() actor: Actor, @Query() query: FleetMapQueryDto) {
    return this.locations.liveFleetMap(actor, query.status);
  }

  @Get('vehicles')
  @ApiOperation({ summary: 'Vehicles inside the operator territory or fleet' })
  vehiclesList(@CurrentActor() actor: Actor) {
    return this.vehicles.list(actor);
  }

  @Get('parcels')
  @ApiOperation({ summary: 'Parcels whose customers sit inside the territory' })
  parcels(@CurrentActor() actor: Actor) {
    return this.ops.parcels(actor);
  }

  @Get('parcels/:id')
  @ApiOperation({ summary: 'One parcel if the customer is in scope' })
  async parcelOne(@CurrentActor() actor: Actor, @Param('id') id: string) {
    const parcel = await this.prisma.parcelShipment.findUnique({
      where: { id: BigInt(id) },
    });
    if (!parcel) {
      throw new NotFoundException('Parcel not found');
    }
    const customer = await this.prisma.user.findUnique({
      where: { id: parcel.customerId },
      select: { districtId: true, stateId: true },
    });
    this.scopes.assertParcel(actor, {
      customerId: parcel.customerId,
      customerDistrictId: customer?.districtId ?? null,
      customerStateId: customer?.stateId ?? null,
    });
    return {
      id: parcel.id.toString(),
      publicRef: parcel.publicRef,
      customerId: parcel.customerId.toString(),
      status: parcel.status,
    };
  }

  @Get('fleet')
  @ApiOperation({ summary: 'Fleet owners inside the operator territory' })
  fleet(@CurrentActor() actor: Actor) {
    return this.ops.fleet(actor);
  }

  @Get('corporate')
  @ApiOperation({ summary: 'Corporate accounts in scope' })
  corporate(@CurrentActor() actor: Actor) {
    return this.admin.corporateAccounts(actor);
  }

  @Get('payments')
  @RequirePermissions(Permission.PaymentsRead)
  payments(@CurrentActor() actor: Actor) {
    return this.admin.payments(actor);
  }

  @Get('wallets')
  @RequirePermissions(Permission.WalletsRead)
  walletsList(@CurrentActor() actor: Actor) {
    return this.admin.wallets(actor);
  }

  @Get('ratings')
  ratings(@CurrentActor() actor: Actor) {
    return this.admin.ratings(actor);
  }

  @Get('coupons')
  coupons() {
    return this.admin.coupons();
  }

  @Post('coupons')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  upsertCoupon(@CurrentActor() actor: Actor, @Body() dto: UpsertCouponDto) {
    return this.admin.upsertCoupon(actor, dto);
  }

  @Get('locations')
  locationCatalog() {
    return this.admin.locations();
  }

  @Get('settings')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  settings() {
    return this.admin.settings();
  }

  @Patch('settings')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  patchSetting(@CurrentActor() actor: Actor, @Body() dto: PatchSettingDto) {
    return this.admin.patchSetting(actor, dto);
  }

  @Get('audit')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  audit(@CurrentActor() actor: Actor) {
    return this.admin.auditLog(actor);
  }

  @Get('roles')
  rolesCatalog() {
    return this.admin.rolesCatalog();
  }

  @Get('revenue')
  @ApiOperation({ summary: 'Revenue totals for the authorized territory only' })
  revenue(@CurrentActor() actor: Actor) {
    return this.ops.revenue(actor);
  }

  @Get('reports')
  @ApiOperation({ summary: 'Territory reports; never includes out-of-scope districts' })
  reports(@CurrentActor() actor: Actor) {
    return this.ops.reports(actor);
  }

  @Get('travel-packages')
  @RequirePermissions(Permission.TravelRead)
  @ApiOperation({ summary: 'Admin list of KarnaTravel packages (including drafts)' })
  travelPackages(@CurrentActor() actor: Actor) {
    return this.travel.listAdmin(actor);
  }

  @Post('travel-packages')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @RequirePermissions(Permission.TravelWrite)
  @ApiOperation({ summary: 'Create a travel package' })
  createTravelPackage(@CurrentActor() actor: Actor, @Body() dto: UpsertTravelPackageDto) {
    return this.travel.createPackage(actor, dto);
  }

  @Patch('travel-packages/:id')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @RequirePermissions(Permission.TravelWrite)
  @ApiOperation({ summary: 'Update or publish a travel package' })
  updateTravelPackage(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: PatchTravelPackageDto,
  ) {
    return this.travel.updatePackage(actor, Number(id), dto);
  }

  @Get('travel-bookings')
  @RequirePermissions(Permission.TravelRead)
  @ApiOperation({ summary: 'Travel bookings in the operator territory' })
  travelBookings(@CurrentActor() actor: Actor) {
    return this.travel.listBookings(actor);
  }

  @Get('bulk')
  @ApiOperation({ summary: 'Bulk booking requests in territory' })
  bulkList(@CurrentActor() actor: Actor) {
    return this.bulk.list(actor);
  }

  @Post('bulk/:id/lifecycle')
  @Roles(UserRole.DISTRICT_HEAD, UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Ops quote, assign, start trip, or invoice a bulk job' })
  bulkLifecycle(@CurrentActor() actor: Actor, @Param('id') id: string, @Body() dto: BulkLifecycleDto) {
    return this.bulk.transition(actor, BigInt(id), dto);
  }

  @Get('fare-rules')
  @ApiOperation({ summary: 'Admin fare_rules used by Local Cab and One-Way quotes' })
  fareRules() {
    return this.ops.fareRules();
  }

  @Patch('fare-rules/:id')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Update a fare_rules row (min KM, per KM, waiting, night, GST, discount)' })
  updateFareRule(@Param('id') id: string, @Body() dto: UpdateFareRuleDto) {
    return this.ops.updateFareRule(Number(id), dto);
  }

  @Get('commission-rules')
  @ApiOperation({ summary: 'Commission percent and which fare components it applies to' })
  commissionRules() {
    return this.ops.commissionRules();
  }

  @Patch('commission-rules/:id')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Admin: percent plus flags for base, GST, toll, parking, waiting, other' })
  updateCommissionRule(@Param('id') id: string, @Body() dto: UpdateCommissionRuleDto) {
    return this.ops.updateCommissionRule(Number(id), dto);
  }

  @Get('withdrawals')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Driver withdrawal queue' })
  withdrawals() {
    return this.wallets.listWithdrawals();
  }

  @Patch('withdrawals/:id')
  @Roles(UserRole.ADMIN, UserRole.SUPER_ADMIN)
  @ApiOperation({ summary: 'Mark a withdrawal paid or rejected (reject credits the wallet via ledger)' })
  reviewWithdrawal(
    @CurrentActor() actor: Actor,
    @Param('id') id: string,
    @Body() dto: ReviewWithdrawalDto,
  ) {
    return this.wallets.reviewWithdrawal(actor, BigInt(id), dto.status);
  }
}
