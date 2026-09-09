import { PrismaService } from '../prisma/prisma.service';
import {
  ACTIVE_PARCEL_STATUSES,
  ACTIVE_RIDE_STATUSES,
  VEHICLE_MAINTENANCE,
} from './driver-duty';

export async function driverCanReceiveOffers(prisma: PrismaService, userId: bigint) {
  const driver = await prisma.driver.findUnique({
    where: { userId },
    include: {
      user: { select: { status: true } },
      vehicles: { select: { status: true } },
      documents: { select: { status: true } },
    },
  });
  if (!driver?.online || driver.user.status === 'SUSPENDED') {
    return false;
  }
  if (driver.vehicles.some((row) => VEHICLE_MAINTENANCE.has(row.status))) {
    return false;
  }
  if (driver.documents.some((row) => row.status === 'expired')) {
    return false;
  }
  const [ride, parcel] = await Promise.all([
    prisma.booking.findFirst({
      where: { driverId: driver.id, status: { in: ACTIVE_RIDE_STATUSES } },
      select: { id: true },
    }),
    prisma.parcelShipment.findFirst({
      where: { driverId: driver.id, status: { in: [...ACTIVE_PARCEL_STATUSES] } },
      select: { id: true },
    }),
  ]);
  return !ride && !parcel;
}
