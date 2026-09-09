import {
  canRefundPayment,
  canRetryPayment,
  invoiceStatus,
  normalizePaymentMethod,
  refundKind,
  webhookCanonical,
} from './payment.model';
import { signPaymentWebhook, verifyPaymentWebhookSignature } from './payment-signature';

describe('payment model', () => {
  it('normalizes cash, upi, card, wallet, advance, and partial', () => {
    expect(normalizePaymentMethod('Cash')).toBe('cash');
    expect(normalizePaymentMethod('COD')).toBe('cash');
    expect(normalizePaymentMethod('UPI')).toBe('upi');
    expect(normalizePaymentMethod('PhonePe')).toBe('upi');
    expect(normalizePaymentMethod('card')).toBe('card');
    expect(normalizePaymentMethod('wallet')).toBe('wallet');
    expect(normalizePaymentMethod('advance')).toBe('advance');
    expect(normalizePaymentMethod('partial')).toBe('partial');
  });

  it('rejects unknown methods instead of capturing them as cash', () => {
    expect(() => normalizePaymentMethod('bitcoin')).toThrow('Unsupported payment method');
  });

  it('treats failed rows as retryable and captured rows as refundable', () => {
    expect(canRetryPayment('failed')).toBe(true);
    expect(canRetryPayment('captured')).toBe(false);
    expect(canRefundPayment('captured')).toBe(true);
    expect(canRefundPayment('pending')).toBe(false);
    expect(refundKind(400, 1000)).toBe('partial_refund');
    expect(refundKind(1000, 1000)).toBe('refund');
  });

  it('derives invoice status from paid and refunded totals', () => {
    expect(invoiceStatus(0, 0, 1000)).toBe('issued');
    expect(invoiceStatus(400, 0, 1000)).toBe('partial');
    expect(invoiceStatus(1000, 0, 1000)).toBe('paid');
    expect(invoiceStatus(1000, 1000, 1000)).toBe('refunded');
  });
});

describe('payment webhook signature', () => {
  it('accepts only HMAC of event|ref|amount', () => {
    const secret = 'karnacab-dev-pay-hook';
    const canonical = webhookCanonical({
      event: 'payment.captured',
      paymentRef: 'KCPTEST',
      amountPaise: 21600,
    });
    const signature = signPaymentWebhook(secret, canonical);
    expect(verifyPaymentWebhookSignature(secret, canonical, signature)).toBe(true);
    expect(verifyPaymentWebhookSignature(secret, canonical, 'deadbeef')).toBe(false);
    expect(verifyPaymentWebhookSignature(secret, canonical, undefined)).toBe(false);
  });
});
