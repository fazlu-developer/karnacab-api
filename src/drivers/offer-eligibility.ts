export function haversineKm(lat1: number, lng1: number, lat2: number, lng2: number) {
  const toRad = (value: number) => (value * Math.PI) / 180;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
  return Math.round(6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)) * 100) / 100;
}

export function estimatedEarningsPaise(totalPaise: number, commissionPaise?: number | null) {
  if (commissionPaise == null || !Number.isFinite(commissionPaise)) {
    return Math.max(0, totalPaise);
  }
  return Math.max(0, totalPaise - commissionPaise);
}

export function vehicleMatches(driverCategories: string[], requested: string) {
  if (!driverCategories.length) {
    return true;
  }
  return driverCategories.includes(requested);
}

export function withinOfferRadius(input: {
  driverLat?: number | null;
  driverLng?: number | null;
  pickupLat?: number | null;
  pickupLng?: number | null;
  radiusKm: number;
}) {
  if (
    input.driverLat == null ||
    input.driverLng == null ||
    input.pickupLat == null ||
    input.pickupLng == null
  ) {
    return true;
  }
  return haversineKm(input.driverLat, input.driverLng, input.pickupLat, input.pickupLng) <= input.radiusKm;
}

export function parcelServiceAllowed(input: {
  parcelEnabled: boolean;
  status: string;
  paymentStatus: string;
  complianceConfirmed: boolean;
  parcelType: string;
  allowedTypes: string[];
  prohibitedTypes: string[];
}) {
  return (
    input.parcelEnabled &&
    input.status === 'created' &&
    (input.paymentStatus === 'paid' || input.paymentStatus === 'cod') &&
    input.complianceConfirmed &&
    input.allowedTypes.includes(input.parcelType) &&
    !input.prohibitedTypes.includes(input.parcelType)
  );
}

export function maskCustomer(name?: string | null, phone?: string | null) {
  const first = (name ?? 'Customer').trim().split(/\s+/)[0] || 'Customer';
  const digits = (phone ?? '').replace(/\D/g, '');
  const tail = digits.length >= 4 ? digits.slice(-4) : '****';
  return {
    name: first,
    phoneMasked: `******${tail}`,
  };
}
