import { createHmac, timingSafeEqual } from 'crypto';
import { webhookCanonical } from './payment.model';

export function signPaymentWebhook(secret: string, canonical: string): string {
  return createHmac('sha256', secret).update(canonical).digest('hex');
}

export function verifyPaymentWebhookSignature(
  secret: string,
  canonical: string,
  signature: string | undefined,
): boolean {
  if (!secret || !signature) {
    return false;
  }
  const expected = signPaymentWebhook(secret, canonical);
  const left = Buffer.from(expected);
  const right = Buffer.from(signature.trim().toLowerCase());
  if (left.length !== right.length) {
    return false;
  }
  return timingSafeEqual(left, right);
}

export function canonicalFromWebhookBody(body: {
  event?: string;
  paymentRef?: string;
  amountPaise?: number;
}): string {
  return webhookCanonical({
    event: String(body.event ?? ''),
    paymentRef: String(body.paymentRef ?? ''),
    amountPaise: Number(body.amountPaise ?? 0),
  });
}
