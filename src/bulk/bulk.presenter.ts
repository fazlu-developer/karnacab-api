import { UserRole, VehicleCategory } from '@prisma/client';
import {
  BULK_STATUS_LABELS,
  BulkStatus,
  bulkAllowedActions,
  parseEventList,
} from './bulk-lifecycle';

export function presentBulk(
  row: {
    id: bigint;
    publicRef: string;
    customerId: bigint;
    corporateAccountId?: bigint | null;
    eventKey: string;
    vehicleCount: number;
    category: string;
    pickupText: string;
    dropText: string;
    eventDate: Date;
    eventTime: string;
    passengers: number;
    requirements?: string | null;
    quotePaise?: bigint | null;
    quoteSnapshot?: unknown;
    advancePaise?: bigint | null;
    invoicePaise?: bigint | null;
    assignmentNotes?: string | null;
    paymentStatus: string;
    paymentMethod?: string | null;
    status: string;
    createdAt: Date;
  },
  extras?: { events?: { key: string; label: string }[]; role?: UserRole; quote?: unknown },
) {
  const status = row.status as BulkStatus;
  const events = extras?.events ?? parseEventList();
  const snapshot = extras?.quote ?? row.quoteSnapshot;
  const totalPaise =
    snapshot && typeof snapshot === 'object' && 'totalPaise' in snapshot
      ? Number((snapshot as { totalPaise: number }).totalPaise)
      : row.quotePaise
        ? Number(row.quotePaise)
        : null;
  const role = extras?.role === UserRole.CUSTOMER || extras?.role === UserRole.CORPORATE ? 'CUSTOMER' : 'OPS';
  return {
    id: row.id.toString(),
    publicRef: row.publicRef,
    kind: 'bulk' as const,
    eventKey: row.eventKey,
    eventLabel: events.find((item) => item.key === row.eventKey)?.label ?? row.eventKey,
    vehicleCount: row.vehicleCount,
    category: row.category as VehicleCategory,
    pickupText: row.pickupText,
    dropText: row.dropText,
    eventDate: row.eventDate.toISOString().slice(0, 10),
    eventTime: row.eventTime,
    passengers: row.passengers,
    requirements: row.requirements ?? null,
    assignmentNotes: row.assignmentNotes ?? null,
    paymentStatus: row.paymentStatus,
    paymentMethod: row.paymentMethod ?? null,
    status,
    statusLabel: BULK_STATUS_LABELS[status] ?? row.status,
    tracking: [
      'Request',
      'Quotation',
      'Accepted',
      'Advance',
      'Assignment',
      'Trip',
      'Final Invoice',
    ],
    quote: snapshot,
    fare:
      totalPaise == null
        ? null
        : { source: 'server', totalPaise, totalRupees: totalPaise / 100, currency: 'INR' },
    advancePaise: row.advancePaise == null ? null : Number(row.advancePaise),
    invoicePaise: row.invoicePaise == null ? null : Number(row.invoicePaise),
    allowedActions: extras?.role ? bulkAllowedActions(status, role) : [],
    createdAt: row.createdAt,
  };
}
