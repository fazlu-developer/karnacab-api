import { ForbiddenException, Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';
import { KycService } from '../kyc/kyc.service';

@Injectable()
export class DriversService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
    private readonly kyc: KycService,
  ) {}

  async list(actor: Actor) {
    const rows = await this.prisma.driver.findMany({
      where: this.scopes.driverWhere(actor),
      include: {
        user: { select: { name: true, phone: true, email: true, districtId: true, stateId: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
      },
      take: 100,
    });
    return {
      drivers: rows.map((driver) => this.serialize(driver)),
    };
  }

  async one(actor: Actor, driverId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { id: driverId },
      include: {
        user: { select: { name: true, phone: true, email: true, districtId: true, stateId: true } },
        vehicles: { select: { districtId: true, district: { select: { stateId: true } } } },
      },
    });
    if (!driver) {
      throw new NotFoundException('Driver not found');
    }
    this.scopes.assertDriver(actor, {
      id: driver.id,
      userId: driver.userId,
      fleetOwnerId: driver.fleetOwnerId,
      userDistrictId: driver.user.districtId,
      userStateId: driver.user.stateId,
      vehicleDistrictIds: driver.vehicles.map((row) => row.districtId),
      vehicleStateIds: driver.vehicles.map((row) => row.district.stateId),
    });
    return this.serialize(driver);
  }

  async me(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      include: { user: { select: { name: true, phone: true, email: true } } },
    });
    if (!driver) {
      throw new ForbiddenException('Driver profile required');
    }
    const kyc = await this.kyc.snapshot(userId);
    return {
      id: driver.id.toString(),
      userId: driver.userId.toString(),
      online: driver.online,
      ratingAvg: Number(driver.ratingAvg),
      name: driver.user.name,
      phone: driver.user.phone,
      email: driver.user.email,
      kycStatus: kyc.kycStatus,
      canGoOnline: kyc.canGoOnline,
      nextStep: kyc.nextStep,
    };
  }

  async setOnline(userId: bigint, online: boolean) {
    const existing = await this.prisma.driver.findUnique({ where: { userId } });
    if (!existing) {
      throw new ForbiddenException('Driver profile required');
    }
    if (online) {
      await this.kyc.assertCanGoOnline(userId);
    }
    const driver = await this.prisma.driver.update({
      where: { userId },
      data: { online },
      include: { user: { select: { name: true, phone: true, email: true } } },
    });
    return {
      id: driver.id.toString(),
      online: driver.online,
      name: driver.user.name,
    };
  }

  private serialize(driver: {
    id: bigint;
    userId: bigint;
    fleetOwnerId: bigint | null;
    online: boolean;
    ratingAvg: unknown;
    user: { name: string; phone?: string | null; email: string; districtId?: number | null };
  }) {
    return {
      id: driver.id.toString(),
      userId: driver.userId.toString(),
      fleetOwnerId: driver.fleetOwnerId?.toString() ?? null,
      online: driver.online,
      ratingAvg: Number(driver.ratingAvg),
      name: driver.user.name,
      phone: driver.user.phone,
      email: driver.user.email,
      districtId: driver.user.districtId ?? null,
    };
  }
}
