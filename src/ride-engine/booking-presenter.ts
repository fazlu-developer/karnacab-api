import { BookingStatus, UserRole } from '@prisma/client';
import {
  allowedActions,
  LIFECYCLE_LABELS,
  toLegacyStatus,
  toLifecycle,
} from './booking-lifecycle';
import { rideTypeLabel, vehicleTypeLabel } from './ride-catalog';

export type BookingRow = {
  id: bigint;
  publicRef: string;
  customerId: bigint;
  product: string;
  category: string;
  status: string;
  pickupText: string;
  dropText: string;
  pickupLat?: unknown;
  pickupLng?: unknown;
  dropLat?: unknown;
  dropLng?: unknown;
  polyline?: string | null;
  distanceKm?: unknown;
  quotePaise?: bigint | null;
  quoteSnapshot?: unknown;
  scheduledAt?: Date | null;
  returnAt?: Date | null;
  driverId?: bigint | null;
  vehicleId?: bigint | null;
  startOtp?: string | null;
  endOtp?: string | null;
  tripStartedAt?: Date | null;
  tripEndedAt?: Date | null;
  flightNumber?: string | null;
  trainNumber?: string | null;
  terminal?: string | null;
  createdAt: Date;
  stops?: Array<{ seq: number; label: string; lat?: unknown; lng?: unknown }>;
};

export function presentBooking(
  row: BookingRow,
  extras?: {
    customer?: { name: string; phone?: string | null };
    driverUser?: { name: string; phone?: string | null };
    nearby?: { lat?: unknown; lng?: unknown } | null;
    quote?: unknown;
    role?: UserRole;
  },
) {
  const lifecycle = toLifecycle(row.status);
  const snapshot =
    extras?.quote && typeof extras.quote === 'object'
      ? (extras.quote as Record<string, unknown>)
      : row.quoteSnapshot && typeof row.quoteSnapshot === 'object'
        ? (row.quoteSnapshot as Record<string, unknown>)
        : null;
  const totalPaise =
    typeof snapshot?.totalPaise === 'number'
      ? snapshot.totalPaise
      : row.quotePaise
        ? Number(row.quotePaise)
        : null;
  const fare =
    totalPaise == null
      ? null
      : {
          source: 'server',
          totalPaise,
          totalRupees: totalPaise / 100,
          currency: 'INR',
          breakdown: snapshot?.breakdown ?? null,
        };

  return {
    id: row.id.toString(),
    publicRef: row.publicRef,
    customerId: row.customerId.toString(),
    product: row.product,
    category: row.category,
    rideType: { key: row.product, label: rideTypeLabel(row.product) },
    vehicleType: { key: row.category, label: vehicleTypeLabel(row.category) },
    status: toLegacyStatus(lifecycle),
    statusCode: row.status,
    lifecycle,
    lifecycleLabel: LIFECYCLE_LABELS[lifecycle],
    pickupText: row.pickupText,
    dropText: row.dropText,
    pickupLat: row.pickupLat == null ? null : Number(row.pickupLat),
    pickupLng: row.pickupLng == null ? null : Number(row.pickupLng),
    dropLat: row.dropLat == null ? null : Number(row.dropLat),
    dropLng: row.dropLng == null ? null : Number(row.dropLng),
    polyline: row.polyline ?? null,
    distanceKm: row.distanceKm == null ? null : Number(row.distanceKm),
    scheduledAt: row.scheduledAt ?? null,
    returnAt: row.returnAt ?? null,
    flightNumber: row.flightNumber ?? null,
    trainNumber: row.trainNumber ?? null,
    terminal: row.terminal ?? null,
    stops: (row.stops ?? []).map((stop) => ({
      seq: stop.seq,
      label: stop.label,
      lat: stop.lat == null ? null : Number(stop.lat),
      lng: stop.lng == null ? null : Number(stop.lng),
    })),
    quotePaise: row.quotePaise?.toString() ?? null,
    quoteRupees: fare?.totalRupees ?? null,
    fare,
    quote: snapshot,
    driverId: row.driverId?.toString() ?? null,
    vehicleId: row.vehicleId?.toString() ?? null,
    startOtp: extras?.role === UserRole.DRIVER ? null : row.startOtp ?? null,
    endOtp:
      extras?.role === UserRole.DRIVER
        ? null
        : lifecycle === 'started' || lifecycle === 'completed'
          ? row.endOtp ?? null
          : null,
    tripStartedAt: row.tripStartedAt ?? null,
    tripEndedAt: row.tripEndedAt ?? null,
    createdAt: row.createdAt,
    customerName: extras?.customer?.name,
    customerPhone: extras?.customer?.phone,
    driverName: extras?.driverUser?.name,
    driverPhone: extras?.driverUser?.phone,
    driverLat: extras?.nearby?.lat == null ? null : Number(extras.nearby.lat),
    driverLng: extras?.nearby?.lng == null ? null : Number(extras.nearby.lng),
    allowedActions: extras?.role ? allowedActions(row.status, extras.role) : [],
  };
}

export function isLiveLegacyStatus(status: string | BookingStatus) {
  const lifecycle = toLifecycle(String(status));
  return (
    lifecycle === 'driver_assigned' ||
    lifecycle === 'driver_arriving' ||
    lifecycle === 'driver_arrived' ||
    lifecycle === 'started'
  );
}
