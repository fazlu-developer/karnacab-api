import { alertsForDocument, alertWindowForDays, daysUntilExpiry } from './driver-document-alerts';

describe('driver document alerts', () => {
  const now = new Date('2026-09-07T12:00:00+05:30');

  it('emits expired, 7, 15 and 30 day windows', () => {
    expect(alertWindowForDays(-1)).toBe('expired');
    expect(alertWindowForDays(7)).toBe('7');
    expect(alertWindowForDays(15)).toBe('15');
    expect(alertWindowForDays(30)).toBe('30');
    expect(alertWindowForDays(31)).toBeNull();
  });

  it('prefers the tightest remaining window', () => {
    const alert = alertsForDocument(
      { type: 'INSURANCE', label: 'Insurance', status: 'verified', expiresAt: '2026-09-14' },
      now,
    )[0];
    expect(alert.window).toBe('7');
    expect(daysUntilExpiry('2026-09-14', now)).toBe(7);
  });
});
