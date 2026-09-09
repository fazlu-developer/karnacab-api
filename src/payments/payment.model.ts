export const PAYMENT_METHODS = ['cash', 'upi', 'card', 'wallet', 'advance', 'partial'] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export const GATEWAY_METHODS = ['upi', 'card'] as const;

export const PAYMENT_KINDS = ['payment', 'refund', 'partial_refund', 'cancellation_charge'] as const;
export type PaymentKind = (typeof PAYMENT_KINDS)[number];

export const PAYMENT_INTENTS = ['capture', 'advance', 'partial', 'refund', 'cancel_fee'] as const;
export type PaymentIntent = (typeof PAYMENT_INTENTS)[number];

export const PAYMENT_STATUSES = [
  'pending',
  'failed',
  'captured',
  'refunded',
  'partially_refunded',
] as const;
export type PaymentStatus = (typeof PAYMENT_STATUSES)[number];

export const INVOICE_KINDS = ['ride', 'parcel', 'travel', 'bulk', 'corporate'] as const;
export type InvoiceKind = (typeof INVOICE_KINDS)[number];

export function normalizePaymentMethod(raw: string | null | undefined): PaymentMethod {
  const value = (raw ?? 'cash').trim().toLowerCase();
  if (value === 'cod' || value === 'cash_on_delivery') {
    return 'cash';
  }
  if (value === 'razorpay' || value === 'gpay' || value === 'phonepe' || value === 'paytm') {
    return 'upi';
  }
  if (value === 'credit' || value === 'debit' || value === 'netbanking') {
    return 'card';
  }
  if ((PAYMENT_METHODS as readonly string[]).includes(value)) {
    return value as PaymentMethod;
  }
  throw new Error('Unsupported payment method');
}

export function intentForMethod(method: PaymentMethod, explicit?: string): PaymentIntent {
  if (explicit === 'advance' || method === 'advance') {
    return 'advance';
  }
  if (explicit === 'partial' || method === 'partial') {
    return 'partial';
  }
  if (explicit === 'cancel_fee') {
    return 'cancel_fee';
  }
  if (explicit === 'refund') {
    return 'refund';
  }
  return 'capture';
}

export function gatewayForMethod(method: PaymentMethod, configured: string): string {
  if (method === 'wallet') {
    return 'wallet';
  }
  if (method === 'cash') {
    return 'cash';
  }
  if (method === 'advance' || method === 'partial') {
    return configured || 'demo';
  }
  return configured || 'demo';
}

export function needsGatewayWebhook(method: PaymentMethod): boolean {
  return method === 'upi' || method === 'card' || method === 'advance' || method === 'partial';
}

export function webhookCanonical(input: {
  event: string;
  paymentRef: string;
  amountPaise: number;
}): string {
  return `${input.event}|${input.paymentRef}|${input.amountPaise}`;
}

export function canRetryPayment(status: string): boolean {
  return status === 'failed' || status === 'pending';
}

export function canRefundPayment(status: string): boolean {
  return status === 'captured' || status === 'partially_refunded';
}

export function refundKind(amountPaise: number, capturedPaise: number): PaymentKind {
  return amountPaise < capturedPaise ? 'partial_refund' : 'refund';
}

export function invoiceStatus(paidPaise: number, refundedPaise: number, totalPaise: number): string {
  if (refundedPaise > 0 && paidPaise - refundedPaise <= 0) {
    return 'refunded';
  }
  if (paidPaise <= 0) {
    return 'issued';
  }
  if (paidPaise - refundedPaise >= totalPaise) {
    return 'paid';
  }
  return 'partial';
}
