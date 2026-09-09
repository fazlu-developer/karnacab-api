import { UserRole, VehicleCategory } from '@prisma/client';
import {
  PARCEL_STATUS_LABELS,
  normalizeParcelStatus,
  parcelAllowedActions,
} from './parcel-lifecycle';

export function presentParcel(
  row: {
    id: bigint;
    publicRef: string;
    customerId: bigint;
    biharLane: boolean;
    parcelType: string;
    description?: string | null;
    weightKg: unknown;
    lengthCm?: unknown;
    widthCm?: unknown;
    heightCm?: unknown;
    quantity?: number;
    category: string;
    pickupText: string;
    dropText: string;
    pickupLat?: unknown;
    pickupLng?: unknown;
    dropLat?: unknown;
    dropLng?: unknown;
    distanceKm?: unknown;
    contactName?: string | null;
    contactPhone?: string | null;
    instructions?: string | null;
    complianceConfirmed?: boolean;
    quotePaise?: bigint | null;
    quoteSnapshot?: unknown;
    paymentStatus?: string;
    paymentMethod?: string | null;
    driverId?: bigint | null;
    vehicleId?: bigint | null;
    pickupOtp?: string | null;
    deliveryPin?: string | null;
    status: string;
    createdAt: Date;
  },
  extras?: {
    quote?: unknown;
    driverName?: string | null;
    role?: UserRole;
  },
) {
  const status = normalizeParcelStatus(row.status);
  const snapshot =
    extras?.quote && typeof extras.quote === 'object'
      ? extras.quote
      : row.quoteSnapshot;
  const totalPaise =
    snapshot && typeof snapshot === 'object' && 'totalPaise' in snapshot
      ? Number((snapshot as { totalPaise: number }).totalPaise)
      : row.quotePaise
        ? Number(row.quotePaise)
        : null;
  const role =
    extras?.role === UserRole.DRIVER
      ? 'DRIVER'
      : extras?.role === UserRole.CUSTOMER || extras?.role === UserRole.CORPORATE
        ? 'CUSTOMER'
        : 'OPS';
  return {
    id: row.id.toString(),
    publicRef: row.publicRef,
    kind: 'parcel' as const,
    lane: row.biharLane ? 'BIHAR' : 'LOCAL',
    biharLane: row.biharLane,
    parcelType: row.parcelType,
    description: row.description ?? null,
    weightKg: Number(row.weightKg),
    lengthCm: row.lengthCm == null ? null : Number(row.lengthCm),
    widthCm: row.widthCm == null ? null : Number(row.widthCm),
    heightCm: row.heightCm == null ? null : Number(row.heightCm),
    quantity: row.quantity ?? 1,
    category: row.category as VehicleCategory,
    pickupText: row.pickupText,
    dropText: row.dropText,
    pickupLat: row.pickupLat == null ? null : Number(row.pickupLat),
    pickupLng: row.pickupLng == null ? null : Number(row.pickupLng),
    dropLat: row.dropLat == null ? null : Number(row.dropLat),
    dropLng: row.dropLng == null ? null : Number(row.dropLng),
    distanceKm: row.distanceKm == null ? null : Number(row.distanceKm),
    contactName: row.contactName ?? null,
    contactPhone: row.contactPhone ?? null,
    instructions: row.instructions ?? null,
    complianceConfirmed: row.complianceConfirmed ?? false,
    paymentStatus: row.paymentStatus ?? 'unpaid',
    paymentMethod: row.paymentMethod ?? null,
    status,
    statusLabel: PARCEL_STATUS_LABELS[status],
    tracking: PARCEL_STATUS_LABELS[status],
    quote: snapshot,
    fare:
      totalPaise == null
        ? null
        : { source: 'server', totalPaise, totalRupees: totalPaise / 100, currency: 'INR' },
    driverId: row.driverId?.toString() ?? null,
    driverName: extras?.driverName ?? null,
    vehicleId: row.vehicleId?.toString() ?? null,
    pickupOtp: extras?.role === UserRole.CUSTOMER || extras?.role === UserRole.DRIVER ? row.pickupOtp : null,
    deliveryPin:
      extras?.role === UserRole.CUSTOMER || extras?.role === UserRole.DRIVER ? row.deliveryPin : null,
    allowedActions: extras?.role ? parcelAllowedActions(status, role) : [],
    createdAt: row.createdAt,
  };
}
