import { UserRole } from '@prisma/client';
import { estimatedEarningsPaise } from '../drivers/offer-eligibility';
import { toLifecycle } from './booking-lifecycle';

function mapsDir(
  origin?: { lat: number; lng: number } | null,
  dest?: { lat: number; lng: number } | null,
) {
  if (!dest) {
    return null;
  }
  const o = origin ? `${origin.lat},${origin.lng}` : '';
  const d = `${dest.lat},${dest.lng}`;
  return o
    ? `https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(o)}&destination=${encodeURIComponent(d)}&travelmode=driving`
    : `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d)}`;
}

function point(lat?: number | null, lng?: number | null) {
  if (lat == null || lng == null || !Number.isFinite(lat) || !Number.isFinite(lng)) {
    return null;
  }
  return { lat, lng };
}

export function withTripView(
  presented: Record<string, unknown>,
  extras: {
    role?: UserRole;
    startOtp?: string | null;
    endOtp?: string | null;
    tripStartedAt?: Date | null;
    tripEndedAt?: Date | null;
    driverLat?: number | null;
    driverLng?: number | null;
    pickupLat?: number | null;
    pickupLng?: number | null;
    dropLat?: number | null;
    dropLng?: number | null;
    safety?: { sos: string; helpline: string };
    ratings?: Array<{ fromRole: string; stars: number; comment?: string | null }>;
  },
) {
  const lifecycle = toLifecycle(String(presented.statusCode ?? presented.lifecycle ?? ''));
  const isDriver = extras.role === UserRole.DRIVER;
  const showPins = extras.role !== UserRole.DRIVER;
  const pickup = point(extras.pickupLat ?? Number(presented.pickupLat), extras.pickupLng ?? Number(presented.pickupLng));
  const drop = point(extras.dropLat ?? Number(presented.dropLat), extras.dropLng ?? Number(presented.dropLng));
  const driver = point(extras.driverLat ?? null, extras.driverLng ?? null);
  const fare = presented.fare as { totalPaise?: number; totalRupees?: number; breakdown?: unknown } | null;
  const snapshot = presented.quote as { totalPaise?: number; commissionPaise?: number; breakdown?: unknown } | null;
  const totalPaise = fare?.totalPaise ?? snapshot?.totalPaise ?? 0;
  const earningsPaise = estimatedEarningsPaise(totalPaise, snapshot?.commissionPaise);
  const startedAt = extras.tripStartedAt ?? null;
  const endedAt = extras.tripEndedAt ?? null;
  const elapsedSeconds =
    startedAt != null
      ? Math.max(0, Math.floor(((endedAt ?? new Date()).getTime() - startedAt.getTime()) / 1000))
      : 0;
  return {
    ...presented,
    id: String(presented.id ?? ''),
    kind: presented.kind ?? 'ride',
    pickup: presented.pickupText,
    destination: presented.dropText,
    startOtp: showPins ? extras.startOtp ?? presented.startOtp ?? null : null,
    endOtp: showPins && (lifecycle === 'started' || lifecycle === 'completed') ? extras.endOtp ?? null : null,
    tripStartedAt: startedAt,
    tripEndedAt: endedAt,
    tripElapsedSeconds: elapsedSeconds,
    navigation: {
      mode: lifecycle === 'started' ? 'drop' : 'pickup',
      pickupUrl: mapsDir(driver ?? drop, pickup),
      dropUrl: mapsDir(driver ?? pickup, drop),
      currentUrl: lifecycle === 'started' ? mapsDir(driver ?? pickup, drop) : mapsDir(driver, pickup),
    },
    safety: extras.safety ?? { sos: '112', helpline: '112' },
    estimatedEarningsPaise: earningsPaise,
    estimatedEarningsRupees: earningsPaise / 100,
    invoice:
      lifecycle === 'completed'
        ? {
            publicRef: presented.publicRef,
            fareRupees: (fare?.totalRupees ?? totalPaise / 100) as number,
            earningsRupees: earningsPaise / 100,
            currency: 'INR',
            breakdown: fare?.breakdown ?? snapshot?.breakdown ?? null,
            status: 'issued',
          }
        : null,
    ratings: extras.ratings ?? [],
    canRate: lifecycle === 'completed' && !(extras.ratings ?? []).some((row) => row.fromRole === (isDriver ? 'DRIVER' : 'CUSTOMER')),
    customer:
      presented.customer ??
      (presented.customerName
        ? { name: presented.customerName, phone: presented.customerPhone }
        : null),
  };
}
