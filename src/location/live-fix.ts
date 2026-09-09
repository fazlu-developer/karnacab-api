export type LiveFix = {
  driverId: string;
  vehicleId: string | null;
  bookingId: string | null;
  districtId: number | null;
  lat: number;
  lng: number;
  heading: number | null;
  speed: number | null;
  tripStatus: string;
  recordedAt: string;
  persistedAt?: string | null;
};

export function liveDriverKey(driverId: bigint | string) {
  return `kc:loc:driver:${driverId.toString()}`;
}

export function liveBookingChannel(bookingId: bigint | string) {
  return `kc:loc:booking:${bookingId.toString()}`;
}

export function liveDistrictChannel(districtId: number) {
  return `kc:loc:district:${districtId}`;
}

export function metersBetween(lat1: number, lng1: number, lat2: number, lng2: number) {
  const toRad = (value: number) => (value * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return 6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

export function shouldSkipPing(prev: LiveFix | null, next: { lat: number; lng: number; recordedAt: number }) {
  if (!prev) {
    return false;
  }
  const prevAt = Date.parse(prev.recordedAt);
  if (!Number.isFinite(prevAt)) {
    return false;
  }
  const dt = next.recordedAt - prevAt;
  if (dt < 800 && metersBetween(prev.lat, prev.lng, next.lat, next.lng) < 5) {
    return true;
  }
  return false;
}

export function shouldPersistFix(prev: LiveFix | null, next: { lat: number; lng: number; recordedAt: number }) {
  if (!prev) {
    return true;
  }
  const prevAt = Date.parse(prev.recordedAt);
  if (!Number.isFinite(prevAt) || next.recordedAt - prevAt >= 15_000) {
    return true;
  }
  return metersBetween(prev.lat, prev.lng, next.lat, next.lng) >= 40;
}

export function isStale(recordedAt: string, now = Date.now()) {
  const at = Date.parse(recordedAt);
  return !Number.isFinite(at) || now - at > 45_000;
}
