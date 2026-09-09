export const DOC_TYPES = [
  'LICENSE',
  'RC',
  'INSURANCE',
  'PERMIT',
  'FITNESS',
  'PUC',
  'SELFIE',
  'ID_PROOF',
] as const;

export type DocType = (typeof DOC_TYPES)[number];

export const DOC_STATUSES = [
  'pending',
  'under_review',
  'verified',
  'rejected',
  'expired',
] as const;

export type DocStatus = (typeof DOC_STATUSES)[number];

export const KYC_STATUSES = [
  'pending',
  'under_review',
  'verified',
  'rejected',
] as const;

export type KycStatus = (typeof KYC_STATUSES)[number];

export const DEFAULT_REQUIRED_DOCS: DocType[] = [
  'LICENSE',
  'RC',
  'INSURANCE',
  'SELFIE',
  'ID_PROOF',
];

export const DEFAULT_OPTIONAL_DOCS: DocType[] = ['PERMIT', 'FITNESS', 'PUC'];

export const DOC_LABELS: Record<DocType, string> = {
  LICENSE: 'Driving licence',
  RC: 'Vehicle registration',
  INSURANCE: 'Insurance',
  PERMIT: 'Permit',
  FITNESS: 'Fitness certificate',
  PUC: 'Pollution certificate',
  SELFIE: 'Driver photo',
  ID_PROOF: 'Identity document',
};

export const ALLOWED_MIME = new Set([
  'image/jpeg',
  'image/png',
  'image/webp',
  'application/pdf',
]);
