import { CUSTOMER_NOTIFY_KINDS, DRIVER_NOTIFY_KINDS, NOTIFY_CHANNELS, NOTIFY_EVENTS, OPS_NOTIFY_KINDS } from './notify.catalog';
import { NOTIFY_TEMPLATES } from './notify.events';

describe('notification catalog', () => {
  it('lists reusable channels and product events', () => {
    expect(NOTIFY_CHANNELS).toEqual(['in_app', 'push', 'sms', 'email']);
    expect(NOTIFY_EVENTS).toEqual(
      expect.arrayContaining([
        'otp',
        'booking_confirmation',
        'driver_assigned',
        'refund',
        'kyc_approval',
        'wallet_transaction',
        'support_update',
      ]),
    );
    expect(NOTIFY_TEMPLATES.otp.channels).toEqual(['sms', 'email']);
    expect(CUSTOMER_NOTIFY_KINDS).toEqual(expect.arrayContaining(['booking', 'driver_assigned', 'payment', 'offers']));
    expect(DRIVER_NOTIFY_KINDS).toEqual(expect.arrayContaining(['new_request', 'document_expiry', 'announcements']));
    expect(OPS_NOTIFY_KINDS).toEqual(['complaints', 'assignments', 'alerts', 'approvals']);
  });
});
