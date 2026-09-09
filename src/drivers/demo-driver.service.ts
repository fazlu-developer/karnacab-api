import { Injectable } from '@nestjs/common';
import { PrismaService } from '../prisma/prisma.service';

@Injectable()
export class DemoDriverService {
  constructor(private readonly prisma: PrismaService) {}

  async placeNearby(lat: number, lng: number, districtId?: number) {
    const nearLat = Number(lat) + 0.0018;
    const nearLng = Number(lng) + 0.0012;
    const user = await this.prisma.user.findUnique({
      where: { email: 'driver@karnacab.local' },
      include: { driver: true },
    });
    if (!user) {
      return null;
    }

    await this.prisma.user.update({
      where: { id: user.id },
      data: {
        lastLat: nearLat,
        lastLng: nearLng,
        lastAddress: 'Nearby dummy driver',
        locationUpdatedAt: new Date(),
      },
    });

    const driver =
      user.driver ??
      (await this.prisma.driver.create({
        data: {
          userId: user.id,
          licenseNo: 'BR-NEAR-0001',
          online: true,
          parcelEnabled: true,
        },
      }));

    await this.prisma.driver.update({
      where: { id: driver.id },
      data: { online: true, dutyStatus: 'online' },
    });

    const district =
      districtId ??
      (await this.prisma.district.findFirst({ where: { name: 'Patna' } }))?.id;

    let vehicle = await this.prisma.vehicle.findFirst({
      where: { driverId: driver.id },
    });
    if (!vehicle && district) {
      vehicle = await this.prisma.vehicle.upsert({
        where: { registrationNo: 'BR01NEAR1' },
        update: {
          driverId: driver.id,
          lastLat: nearLat,
          lastLng: nearLng,
          lastFixAt: new Date(),
          status: 'online',
        },
        create: {
          districtId: district,
          driverId: driver.id,
          category: 'BIKE',
          registrationNo: 'BR01NEAR1',
          status: 'online',
          lastLat: nearLat,
          lastLng: nearLng,
          lastFixAt: new Date(),
        },
      });
    } else if (vehicle) {
      vehicle = await this.prisma.vehicle.update({
        where: { id: vehicle.id },
        data: {
          lastLat: nearLat,
          lastLng: nearLng,
          lastFixAt: new Date(),
          status: 'online',
          driverId: driver.id,
        },
      });
    }

    return {
      driverId: driver.id,
      vehicleId: vehicle?.id ?? null,
      name: user.name,
      lat: nearLat,
      lng: nearLng,
    };
  }
}
