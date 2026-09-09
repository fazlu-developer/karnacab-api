import { ForbiddenException, Injectable } from '@nestjs/common';
import { Prisma, UserRole, UserStatus } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import {
  Actor,
  canAccessDistrict,
  canReadBooking,
  canReadDriver,
  canReadFleet,
  canReadParcel,
  canReadBulk,
  canReadCorporateAccount,
  canReadTravelBooking,
  canReadVehicle,
  canReadFranchise,
  canReadLead,
  denyAllWhere,
  isUnrestricted,
} from './territory';

@Injectable()
export class ScopeService {
  constructor(private readonly prisma: PrismaService) {}

  async resolve(userId: bigint): Promise<Actor> {
    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      include: {
        driver: true,
        fleet: true,
      },
    });
    if (!user) {
      throw new ForbiddenException('Account not found');
    }
    if (user.status === UserStatus.SUSPENDED) {
      throw new ForbiddenException('Account is suspended');
    }

    const franchise =
      (await this.prisma.franchise.findFirst({
        where: { ownerUserId: userId, status: 'ACTIVE' },
      })) ??
      (await this.prisma.franchise.findFirst({
        where: { ownerUserId: userId },
        orderBy: { createdAt: 'desc' },
      }));

    let districtId = user.districtId ?? null;
    let stateId = user.stateId ?? null;
    if (
      (user.role === UserRole.DISTRICT_HEAD || user.role === UserRole.FRANCHISE) &&
      franchise?.districtId
    ) {
      districtId = franchise.districtId;
    }
    if (districtId != null && stateId == null) {
      const district = await this.prisma.district.findUnique({
        where: { id: districtId },
        select: { stateId: true },
      });
      stateId = district?.stateId ?? null;
    }

    return {
      userId: user.id,
      role: user.role,
      status: user.status,
      districtId,
      stateId,
      driverId: user.driver?.id ?? null,
      fleetOwnerId: user.fleet?.id ?? null,
      unrestricted: isUnrestricted(user.role),
    };
  }

  franchiseWhere(actor: Actor): Prisma.FranchiseWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.STATE_HEAD:
        return actor.stateId != null ? { stateId: actor.stateId } : denyAllWhere();
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null
          ? { OR: [{ ownerUserId: actor.userId }, { districtId: actor.districtId }] }
          : { ownerUserId: actor.userId };
      default:
        return { ownerUserId: actor.userId };
    }
  }

  districtWhere(actor: Actor): Prisma.DistrictWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { stateId: actor.stateId };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { id: actor.districtId };
    }
    return { id: { in: [] } };
  }

  leadWhere(actor: Actor): Prisma.LeadWhereInput {
    if (actor.unrestricted) {
      return { type: 'SUPPORT' };
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { type: 'SUPPORT', user: { stateId: actor.stateId } };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { type: 'SUPPORT', user: { districtId: actor.districtId } };
    }
    return { id: { in: [] } };
  }

  requireAssignedState(actor: Actor) {
    if (actor.unrestricted) {
      return;
    }
    if (actor.role !== UserRole.STATE_HEAD) {
      throw new ForbiddenException('State Head access required');
    }
    if (actor.stateId == null) {
      throw new ForbiddenException('State assignment is required');
    }
  }

  requireAssignedDistrict(actor: Actor) {
    if (actor.unrestricted) {
      return;
    }
    if (actor.role !== UserRole.DISTRICT_HEAD && actor.role !== UserRole.FRANCHISE) {
      throw new ForbiddenException('District Head or exclusive franchise access required');
    }
    if (actor.districtId == null) {
      throw new ForbiddenException('District assignment is required');
    }
  }

  userWhere(actor: Actor): Prisma.UserWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.STATE_HEAD:
        return actor.stateId != null ? { stateId: actor.stateId } : denyAllWhere();
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null ? { districtId: actor.districtId } : denyAllWhere();
      case UserRole.FLEET_OWNER:
        return actor.fleetOwnerId
          ? {
              OR: [
                { id: actor.userId },
                { driver: { fleetOwnerId: actor.fleetOwnerId } },
              ],
            }
          : denyAllWhere();
      case UserRole.CORPORATE:
        return {
          OR: [{ id: actor.userId }, { corporateEmployment: { some: { userId: actor.userId } } }],
        };
      default:
        return denyAllWhere();
    }
  }

  bookingWhere(actor: Actor): Prisma.BookingWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.CUSTOMER:
      case UserRole.CORPORATE:
        return { customerId: actor.userId };
      case UserRole.DRIVER:
        return actor.driverId ? { driverId: actor.driverId } : denyAllWhere();
      case UserRole.FLEET_OWNER:
        return actor.fleetOwnerId
          ? { vehicle: { fleetOwnerId: actor.fleetOwnerId } }
          : denyAllWhere();
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null
          ? { districtId: actor.districtId }
          : denyAllWhere();
      case UserRole.STATE_HEAD:
        return actor.stateId != null
          ? { district: { stateId: actor.stateId } }
          : denyAllWhere();
      default:
        return denyAllWhere();
    }
  }

  vehicleWhere(actor: Actor): Prisma.VehicleWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.DRIVER:
        return actor.driverId ? { driverId: actor.driverId } : denyAllWhere();
      case UserRole.FLEET_OWNER:
        return actor.fleetOwnerId
          ? { fleetOwnerId: actor.fleetOwnerId }
          : denyAllWhere();
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null
          ? { districtId: actor.districtId }
          : denyAllWhere();
      case UserRole.STATE_HEAD:
        return actor.stateId != null
          ? { district: { stateId: actor.stateId } }
          : denyAllWhere();
      default:
        return denyAllWhere();
    }
  }

  driverWhere(actor: Actor): Prisma.DriverWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.DRIVER:
        return { userId: actor.userId };
      case UserRole.FLEET_OWNER:
        return actor.fleetOwnerId
          ? { fleetOwnerId: actor.fleetOwnerId }
          : denyAllWhere();
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null
          ? {
              OR: [
                { user: { districtId: actor.districtId } },
                { vehicles: { some: { districtId: actor.districtId } } },
              ],
            }
          : denyAllWhere();
      case UserRole.STATE_HEAD:
        return actor.stateId != null
          ? {
              OR: [
                { user: { stateId: actor.stateId } },
                { vehicles: { some: { district: { stateId: actor.stateId } } } },
              ],
            }
          : denyAllWhere();
      default:
        return denyAllWhere();
    }
  }

  fleetWhere(actor: Actor): Prisma.FleetOwnerWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    switch (actor.role) {
      case UserRole.FLEET_OWNER:
        return { userId: actor.userId };
      case UserRole.DISTRICT_HEAD:
      case UserRole.FRANCHISE:
        return actor.districtId != null
          ? { vehicles: { some: { districtId: actor.districtId } } }
          : denyAllWhere();
      case UserRole.STATE_HEAD:
        return actor.stateId != null
          ? { vehicles: { some: { district: { stateId: actor.stateId } } } }
          : denyAllWhere();
      default:
        return denyAllWhere();
    }
  }

  parcelWhere(actor: Actor): Prisma.ParcelShipmentWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
      return { customerId: actor.userId };
    }
    if (actor.role === UserRole.DRIVER && actor.driverId) {
      return { driverId: actor.driverId };
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { customer: { stateId: actor.stateId } };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { customer: { districtId: actor.districtId } };
    }
    return { customerId: { in: [] } };
  }

  async parcelCustomerIds(actor: Actor): Promise<bigint[] | 'all' | 'none'> {
    if (actor.unrestricted) {
      return 'all';
    }
    if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
      return [actor.userId];
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      const users = await this.prisma.user.findMany({
        where: { districtId: actor.districtId },
        select: { id: true },
      });
      return users.map((row) => row.id);
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      const users = await this.prisma.user.findMany({
        where: { stateId: actor.stateId },
        select: { id: true },
      });
      return users.map((row) => row.id);
    }
    return 'none';
  }

  travelBookingWhere(actor: Actor): Prisma.TravelBookingWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
      return { customerId: actor.userId };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return {
        OR: [{ customer: { districtId: actor.districtId } }, { package: { districtId: actor.districtId } }],
      };
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return {
        OR: [{ customer: { stateId: actor.stateId } }, { package: { district: { stateId: actor.stateId } } }],
      };
    }
    return { id: { in: [] } };
  }

  bulkWhere(actor: Actor): Prisma.BulkBookingWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (actor.role === UserRole.CUSTOMER || actor.role === UserRole.CORPORATE) {
      return { customerId: actor.userId };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { customer: { districtId: actor.districtId } };
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { customer: { stateId: actor.stateId } };
    }
    return { id: { in: [] } };
  }

  walletWhere(actor: Actor): Prisma.WalletWhereInput {
    if (actor.unrestricted) {
      return {};
    }
    if (
      actor.role === UserRole.CUSTOMER ||
      actor.role === UserRole.DRIVER ||
      actor.role === UserRole.FLEET_OWNER ||
      actor.role === UserRole.CORPORATE
    ) {
      return { ownerUserId: actor.userId };
    }
    if (
      (actor.role === UserRole.DISTRICT_HEAD || actor.role === UserRole.FRANCHISE) &&
      actor.districtId != null
    ) {
      return { owner: { districtId: actor.districtId } };
    }
    if (actor.role === UserRole.STATE_HEAD && actor.stateId != null) {
      return { owner: { stateId: actor.stateId } };
    }
    return { id: { in: [] } };
  }

  assertBooking(actor: Actor, booking: Parameters<typeof canReadBooking>[1]) {
    if (!canReadBooking(actor, booking)) {
      throw new ForbiddenException('Access denied for this booking');
    }
  }

  assertVehicle(actor: Actor, vehicle: Parameters<typeof canReadVehicle>[1]) {
    if (!canReadVehicle(actor, vehicle)) {
      throw new ForbiddenException('Access denied for this vehicle');
    }
  }

  assertDriver(actor: Actor, driver: Parameters<typeof canReadDriver>[1]) {
    if (!canReadDriver(actor, driver)) {
      throw new ForbiddenException('Access denied for this driver');
    }
  }

  assertParcel(actor: Actor, parcel: Parameters<typeof canReadParcel>[1]) {
    if (!canReadParcel(actor, parcel)) {
      throw new ForbiddenException('Access denied for this parcel');
    }
  }

  assertBulk(actor: Actor, booking: Parameters<typeof canReadBulk>[1]) {
    if (!canReadBulk(actor, booking)) {
      throw new ForbiddenException('Access denied for this bulk booking');
    }
  }

  assertCorporateAccount(actor: Actor, account: Parameters<typeof canReadCorporateAccount>[1]) {
    if (!canReadCorporateAccount(actor, account)) {
      throw new ForbiddenException('Access denied for this company');
    }
  }

  assertTravelBooking(actor: Actor, booking: Parameters<typeof canReadTravelBooking>[1]) {
    if (!canReadTravelBooking(actor, booking)) {
      throw new ForbiddenException('Access denied for this travel booking');
    }
  }

  assertFleet(actor: Actor, fleet: Parameters<typeof canReadFleet>[1]) {
    if (!canReadFleet(actor, fleet)) {
      throw new ForbiddenException('Access denied for this fleet');
    }
  }

  assertFranchise(actor: Actor, row: Parameters<typeof canReadFranchise>[1]) {
    if (!canReadFranchise(actor, row)) {
      throw new ForbiddenException('Access denied for this franchise');
    }
  }

  assertDistrict(actor: Actor, district: { id: number; stateId: number }) {
    if (!canAccessDistrict(actor, district.id, district.stateId)) {
      throw new ForbiddenException('Access denied for this district');
    }
  }

  assertLead(actor: Actor, lead: Parameters<typeof canReadLead>[1]) {
    if (!canReadLead(actor, lead)) {
      throw new ForbiddenException('Access denied for this complaint');
    }
  }
}
