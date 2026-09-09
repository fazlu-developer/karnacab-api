import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';
import { ScopeService } from '../access/scope.service';
import { Actor } from '../access/territory';

@Injectable()
export class VehiclesService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly scopes: ScopeService,
  ) {}

  async list(actor: Actor) {
    const rows = await this.prisma.vehicle.findMany({
      where: this.scopes.vehicleWhere(actor),
      include: { district: { select: { stateId: true } } },
      take: 100,
    });
    return { vehicles: rows.map((row) => this.serialize(row)) };
  }

  async one(actor: Actor, vehicleId: bigint) {
    const vehicle = await this.prisma.vehicle.findUnique({
      where: { id: vehicleId },
      include: { district: { select: { stateId: true } } },
    });
    if (!vehicle) {
      throw new NotFoundException('Vehicle not found');
    }
    this.scopes.assertVehicle(actor, {
      districtId: vehicle.districtId,
      districtStateId: vehicle.district.stateId,
      driverId: vehicle.driverId,
      fleetOwnerId: vehicle.fleetOwnerId,
    });
    return this.serialize(vehicle);
  }

  async mine(userId: bigint) {
    const driver = await this.prisma.driver.findUnique({
      where: { userId },
      include: { vehicles: true, fleetOwner: true },
    });
    const fleet = await this.prisma.fleetOwner.findUnique({
      where: { userId },
      include: { vehicles: true },
    });

    const vehicles = [
      ...(driver?.vehicles ?? []),
      ...(fleet?.vehicles ?? []),
    ];
    const seen = new Set<string>();
    const unique = vehicles.filter((vehicle) => {
      const id = vehicle.id.toString();
      if (seen.has(id)) {
        return false;
      }
      seen.add(id);
      return true;
    });

    return {
      driverId: driver?.id.toString() ?? null,
      fleetOwnerId: fleet?.id.toString() ?? driver?.fleetOwnerId?.toString() ?? null,
      vehicles: unique.map((vehicle) => this.serialize(vehicle)),
    };
  }

  private serialize(vehicle: {
    id: bigint;
    districtId: number;
    category: string;
    registrationNo: string;
    status: string;
    driverId?: bigint | null;
    fleetOwnerId?: bigint | null;
    lastLat?: unknown;
    lastLng?: unknown;
    lastFixAt?: Date | null;
  }) {
    return {
      id: vehicle.id.toString(),
      districtId: vehicle.districtId,
      category: vehicle.category,
      registrationNo: vehicle.registrationNo,
      status: vehicle.status,
      driverId: vehicle.driverId?.toString() ?? null,
      fleetOwnerId: vehicle.fleetOwnerId?.toString() ?? null,
      lastLat: vehicle.lastLat == null ? null : Number(vehicle.lastLat),
      lastLng: vehicle.lastLng == null ? null : Number(vehicle.lastLng),
      lastFixAt: vehicle.lastFixAt,
    };
  }
}
