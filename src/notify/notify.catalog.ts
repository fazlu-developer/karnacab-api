export const NOTIFY_CHANNELS = ['in_app', 'push', 'sms', 'email'] as const;

export const NOTIFY_EVENTS = [
  'otp',
  'booking_confirmation',
  'driver_assigned',
  'driver_arriving',
  'ride_started',
  'ride_completed',
  'payment',
  'refund',
  'cancellation',
  'parcel_update',
  'document_expiry',
  'kyc_approval',
  'kyc_rejection',
  'wallet_transaction',
  'franchise_approval',
  'coupon',
  'support_update',
  'announcement',
] as const;

export const CUSTOMER_NOTIFY_KINDS = [
  'booking',
  'booking_confirmation',
  'driver_assigned',
  'driver_arriving',
  'trip_started',
  'ride_started',
  'trip_completed',
  'ride_completed',
  'payment',
  'refund',
  'cancellation',
  'parcel',
  'parcel_update',
  'travel',
  'bulk_quotation',
  'corporate',
  'coupon',
  'support_update',
  'offers',
] as const;

export const DRIVER_NOTIFY_KINDS = [
  'new_request',
  'booking_cancelled',
  'cancellation',
  'trip_reminder',
  'earnings',
  'document_expiry',
  'kyc_approval',
  'kyc_rejection',
  'wallet_transaction',
  'incentives',
  'announcements',
] as const;

export const OPS_NOTIFY_KINDS = ['complaints', 'assignments', 'alerts', 'approvals'] as const;

export const SUPPORT_KINDS = [
  'complaint',
  'support',
  'parcel',
  'travel',
  'bulk',
  'corporate',
  'driver',
] as const;

export const SUPPORT_STATUSES = ['open', 'assigned', 'in_progress', 'resolved', 'closed'] as const;

export const SUPPORT_LEGACY_STATUSES = ['pending', 'waiting'] as const;

export const SUPPORT_PRIORITIES = ['low', 'medium', 'high', 'urgent'] as const;

export const SUPPORT_CATEGORIES = [
  'booking',
  'payment',
  'driver',
  'safety',
  'wallet',
  'parcel',
  'travel',
  'other',
] as const;

export type NotifyChannel = (typeof NOTIFY_CHANNELS)[number];
export type NotifyEvent = (typeof NOTIFY_EVENTS)[number];
export type CustomerNotifyKind = (typeof CUSTOMER_NOTIFY_KINDS)[number];
export type DriverNotifyKind = (typeof DRIVER_NOTIFY_KINDS)[number];
export type OpsNotifyKind = (typeof OPS_NOTIFY_KINDS)[number];
export type SupportKind = (typeof SUPPORT_KINDS)[number];
export type SupportStatus = (typeof SUPPORT_STATUSES)[number];
export type SupportPriority = (typeof SUPPORT_PRIORITIES)[number];
export type SupportCategory = (typeof SUPPORT_CATEGORIES)[number];
