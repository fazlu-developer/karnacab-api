import { PackageStatus } from '@prisma/client';
import { asItinerary, asStringList, categoryLabel, TravelCategory } from './travel-categories';

export function presentTravelPackage(
  row: {
    id: number;
    districtId: number | null;
    category: string;
    title: string;
    destination: string;
    places: string;
    durationHours: number;
    durationLabel?: string | null;
    kmIncluded: number;
    vehicleLabel: string;
    driverLabel?: string | null;
    pricePaise: number;
    inclusions: string;
    exclusions: string;
    itinerary?: unknown;
    gallery?: unknown;
    availableDates?: unknown;
    status: PackageStatus;
  },
  categories: TravelCategory[] = [],
) {
  return {
    id: row.id,
    kind: 'travel' as const,
    category: row.category,
    categoryLabel: categoryLabel(row.category, categories.length ? categories : undefined),
    title: row.title,
    destination: row.destination,
    places: row.places,
    durationHours: row.durationHours,
    durationLabel: row.durationLabel || `${row.durationHours} hours`,
    kmIncluded: row.kmIncluded,
    vehicleLabel: row.vehicleLabel,
    driverLabel: row.driverLabel || 'Dedicated driver',
    pricePaise: row.pricePaise,
    priceRupees: row.pricePaise / 100,
    inclusions: row.inclusions,
    exclusions: row.exclusions,
    itinerary: asItinerary(row.itinerary),
    gallery: asStringList(row.gallery),
    availableDates: asStringList(row.availableDates),
    status: row.status,
    districtId: row.districtId,
  };
}

const BOOKING_LABELS: Record<string, string> = {
  created: 'Created',
  confirmed: 'Confirmed',
  cancelled: 'Cancelled',
};

export function presentTravelBooking(
  row: {
    id: bigint;
    publicRef: string;
    customerId: bigint;
    packageId: number;
    travelDate: Date;
    guests: number;
    contactName: string;
    contactPhone: string;
    notes?: string | null;
    quotePaise: bigint;
    quoteSnapshot?: unknown;
    paymentStatus: string;
    paymentMethod?: string | null;
    status: string;
    createdAt: Date;
    package?: Parameters<typeof presentTravelPackage>[0];
  },
  categories: TravelCategory[] = [],
) {
  const pkg = row.package ? presentTravelPackage(row.package, categories) : row.quoteSnapshot;
  const totalPaise = Number(row.quotePaise);
  return {
    id: row.id.toString(),
    publicRef: row.publicRef,
    kind: 'travel' as const,
    packageId: row.packageId,
    package: pkg,
    travelDate: row.travelDate.toISOString().slice(0, 10),
    guests: row.guests,
    contactName: row.contactName,
    contactPhone: row.contactPhone,
    notes: row.notes ?? null,
    paymentStatus: row.paymentStatus,
    paymentMethod: row.paymentMethod ?? null,
    status: row.status,
    statusLabel: BOOKING_LABELS[row.status] ?? row.status,
    fare: {
      source: 'server' as const,
      totalPaise,
      totalRupees: totalPaise / 100,
      currency: 'INR',
    },
    createdAt: row.createdAt,
  };
}
