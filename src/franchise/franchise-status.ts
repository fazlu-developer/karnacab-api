import { FranchiseKind, FranchiseStatus } from '@prisma/client';

export const FRANCHISE_STATUSES = [
  'APPLIED',
  'UNDER_REVIEW',
  'APPROVED',
  'ACTIVE',
  'SUSPENDED',
  'EXPIRED',
  'TERMINATED',
] as const;

export type FranchiseStatusCode = (typeof FRANCHISE_STATUSES)[number];

export const FRANCHISE_STATUS_LABELS: Record<FranchiseStatusCode, string> = {
  APPLIED: 'Applied',
  UNDER_REVIEW: 'Under Review',
  APPROVED: 'Approved',
  ACTIVE: 'Active',
  SUSPENDED: 'Suspended',
  EXPIRED: 'Expired',
  TERMINATED: 'Terminated',
};

export const FRANCHISE_KINDS = ['DISTRICT_HEAD', 'EXCLUSIVE_FRANCHISE'] as const;

export const FRANCHISE_KIND_LABELS: Record<FranchiseKind, string> = {
  DISTRICT_HEAD: 'District Head',
  EXCLUSIVE_FRANCHISE: 'Exclusive District Franchise',
};

export const FRANCHISE_DOC_TYPES = [
  'GSTIN',
  'PAN',
  'AADHAAR',
  'PHOTO',
  'ADDRESS',
  'CANCELLED_CHEQUE',
  'AGREEMENT',
] as const;

export const FRANCHISE_HIERARCHY = [
  'KarnaCab Corporate',
  'State Head',
  'District Head / Exclusive District Franchise',
  'Fleet Owner',
  'Driver',
] as const;

export const LIFECYCLE_TRANSITIONS: Record<FranchiseStatusCode, FranchiseStatusCode[]> = {
  APPLIED: ['UNDER_REVIEW', 'TERMINATED'],
  UNDER_REVIEW: ['APPROVED', 'TERMINATED'],
  APPROVED: ['ACTIVE', 'TERMINATED'],
  ACTIVE: ['SUSPENDED', 'EXPIRED', 'TERMINATED'],
  SUSPENDED: ['ACTIVE', 'TERMINATED'],
  EXPIRED: ['ACTIVE', 'TERMINATED'],
  TERMINATED: [],
};

export function exclusiveSeatKey(districtId: number) {
  return String(districtId);
}

export function holdsExclusiveSeat(status: FranchiseStatus | string) {
  return status === FranchiseStatus.ACTIVE || status === 'ACTIVE';
}

export function canTransition(from: FranchiseStatus | string, to: FranchiseStatus | string) {
  const allowed = LIFECYCLE_TRANSITIONS[from as FranchiseStatusCode] ?? [];
  return allowed.includes(to as FranchiseStatusCode);
}

export function otherActiveBlocks(existingActiveId: bigint | null | undefined, selfId: bigint) {
  return existingActiveId != null && existingActiveId !== selfId;
}
