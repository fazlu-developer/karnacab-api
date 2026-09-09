import { UserRole } from '@prisma/client';
import { rideTypeLabel, vehicleTypeLabel } from '../ride-engine/ride-catalog';
import { estimatedEarningsPaise, maskCustomer } from './offer-eligibility';

function snapshotEarnings(snapshot: unknown, totalPaise: number) {
  const commission =
    snapshot && typeof snapshot === 'object' && 'commissionPaise' in snapshot
      ? Number((snapshot as { commissionPaise?: number }).commissionPaise)
      : undefined;
  return estimatedEarningsPaise(totalPaise, Number.isFinite(commission) ? commission : undefined);
}

export function presentRideOffer(
  presented: Record<string, unknown>,
  extras: {
    customerName?: string | null;
    customerPhone?: string | null;
    paymentMethod?: string | null;
    assigned?: boolean;
  },
) {
  const totalPaise =
    typeof presented.quoteRupees === 'number'
      ? Math.round(presented.quoteRupees * 100)
      : Number(presented.quotePaise ?? 0);
  const earningsPaise = snapshotEarnings(presented.quote ?? presented.fare, totalPaise);
  const customer = extras.assigned
    ? { name: extras.customerName ?? 'Customer', phone: extras.customerPhone ?? null, phoneMasked: extras.customerPhone ?? null }
    : maskCustomer(extras.customerName, extras.customerPhone);
  return {
    ...presented,
    kind: 'ride',
    serviceType: rideTypeLabel(String(presented.product ?? 'LOCAL_CAB')),
    pickup: presented.pickupText,
    destination: presented.dropText,
    vehicle: vehicleTypeLabel(String(presented.category ?? '')),
    paymentType: extras.paymentMethod || 'Cash',
    estimatedFareRupees: totalPaise / 100,
    estimatedEarningsPaise: earningsPaise,
    estimatedEarningsRupees: earningsPaise / 100,
    customer,
    customerName: customer.name,
    customerPhone: extras.assigned ? extras.customerPhone ?? null : customer.phoneMasked,
    actions: ['accept', 'reject'],
  };
}

export function presentParcelOffer(
  presented: Record<string, unknown>,
  extras: {
    assigned?: boolean;
    role?: UserRole;
  } = {},
) {
  const fare = presented.fare as { totalPaise?: number; totalRupees?: number } | null;
  const totalPaise = fare?.totalPaise ?? 0;
  const earningsPaise = snapshotEarnings(presented.quote, totalPaise);
  const contactName = presented.contactName as string | null;
  const contactPhone = presented.contactPhone as string | null;
  const customer = extras.assigned
    ? { name: contactName ?? 'Customer', phone: contactPhone, phoneMasked: contactPhone }
    : maskCustomer(contactName, contactPhone);
  return {
    ...presented,
    kind: 'parcel',
    serviceType: presented.biharLane ? 'Bihar Parcel' : 'Local Parcel',
    pickup: presented.pickupText,
    destination: presented.dropText,
    vehicle: vehicleTypeLabel(String(presented.category ?? '')),
    paymentType: (presented.paymentMethod as string) || 'Online',
    estimatedFareRupees: fare?.totalRupees ?? totalPaise / 100,
    estimatedEarningsPaise: earningsPaise,
    estimatedEarningsRupees: earningsPaise / 100,
    customer,
    contactName: customer.name,
    contactPhone: extras.assigned ? contactPhone : customer.phoneMasked,
    actions: ['accept', 'reject'],
  };
}
