import { Prisma } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { ACTIVE_PARCEL_STATUSES, ACTIVE_RIDE_STATUSES } from './driver-duty';

function asNumber(value: unknown): number | null {
  if (value == null) {
    return null;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : null;
}

export type DriverOfferProfile = {
  driverId: bigint;
  userId: bigint;
  parcelEnabled: boolean;
  categories: string[];
  vehicleIdsByCategory: Map<string, bigint>;
  firstVehicleId: bigint | null;
  districtIds: number[];
  lat: number | null;
  lng: number | null;
  radiusKm: number;
  declinedRideIds: bigint[];
  declinedParcelIds: bigint[];
  name: string;
  phone: string | null;
};

export async function loadDriverOfferProfile(
  prisma: PrismaService | Prisma.TransactionClient,
  userId: bigint,
): Promise<DriverOfferProfile | null> {
  const driver = await prisma.driver.findUnique({
    where: { userId },
    include: {
      user: { select: { name: true, phone: true, lastLat: true, lastLng: true, districtId: true } },
      vehicles: {
        select: { id: true, category: true, districtId: true, lastLat: true, lastLng: true },
      },
    },
  });
  if (!driver) {
    return null;
  }
  const radiusRow = await prisma.systemSetting.findUnique({
    where: { key: 'driver_offer_radius_km' },
  });
  const radiusKm = Math.max(1, Number(radiusRow?.value ?? 30) || 30);
  const declines = await prisma.driverOfferDecline.findMany({
    where: { driverId: driver.id },
    select: { kind: true, targetId: true },
  });
  const categories = [...new Set(driver.vehicles.map((row) => String(row.category)))];
  const vehicleIdsByCategory = new Map<string, bigint>();
  for (const row of driver.vehicles) {
    if (!vehicleIdsByCategory.has(String(row.category))) {
      vehicleIdsByCategory.set(String(row.category), row.id);
    }
  }
  const vehicleFix = driver.vehicles.find((row) => row.lastLat != null && row.lastLng != null);
  return {
    driverId: driver.id,
    userId: driver.userId,
    parcelEnabled: driver.parcelEnabled,
    categories,
    vehicleIdsByCategory,
    firstVehicleId: driver.vehicles[0]?.id ?? null,
    districtIds: [
      ...new Set(
        [driver.user.districtId, ...driver.vehicles.map((row) => row.districtId)].filter(
          (id): id is number => id != null,
        ),
      ),
    ],
    lat: asNumber(driver.user.lastLat) ?? asNumber(vehicleFix?.lastLat),
    lng: asNumber(driver.user.lastLng) ?? asNumber(vehicleFix?.lastLng),
    radiusKm,
    declinedRideIds: declines.filter((row) => row.kind === 'ride').map((row) => row.targetId),
    declinedParcelIds: declines.filter((row) => row.kind === 'parcel').map((row) => row.targetId),
    name: driver.user.name,
    phone: driver.user.phone,
  };
}

export async function driverHasActiveJob(
  prisma: PrismaService | Prisma.TransactionClient,
  driverId: bigint,
) {
  const [ride, parcel] = await Promise.all([
    prisma.booking.findFirst({
      where: { driverId, status: { in: ACTIVE_RIDE_STATUSES } },
      select: { id: true },
    }),
    prisma.parcelShipment.findFirst({
      where: { driverId, status: { in: [...ACTIVE_PARCEL_STATUSES] } },
      select: { id: true },
    }),
  ]);
  return Boolean(ride || parcel);
}

export async function lockDriverRow(
  tx: Prisma.TransactionClient,
  driverId: bigint,
) {
  await tx.$queryRaw(Prisma.sql`SELECT id FROM drivers WHERE id = ${driverId} FOR UPDATE`);
}

export async function lockBookingRow(tx: Prisma.TransactionClient, bookingId: bigint) {
  const rows = await tx.$queryRaw<Array<{ id: bigint; driver_id: bigint | null; status: string }>>(
    Prisma.sql`SELECT id, driver_id, status FROM bookings WHERE id = ${bookingId} FOR UPDATE`,
  );
  return rows[0] ?? null;
}

export async function lockParcelRow(tx: Prisma.TransactionClient, parcelId: bigint) {
  const rows = await tx.$queryRaw<
    Array<{
      id: bigint;
      driver_id: bigint | null;
      status: string;
      payment_status: string;
      compliance_confirmed: number | boolean;
      parcel_type: string;
      category: string;
      pickup_lat: unknown;
      pickup_lng: unknown;
    }>
  >(
    Prisma.sql`SELECT id, driver_id, status, payment_status, compliance_confirmed, parcel_type, category, pickup_lat, pickup_lng
      FROM parcel_shipments WHERE id = ${parcelId} FOR UPDATE`,
  );
  return rows[0] ?? null;
}

export async function recordDecline(
  prisma: PrismaService,
  driverId: bigint,
  kind: 'ride' | 'parcel',
  targetId: bigint,
) {
  await prisma.driverOfferDecline.upsert({
    where: {
      driverId_kind_targetId: { driverId, kind, targetId },
    },
    update: {},
    create: { driverId, kind, targetId },
  });
}
