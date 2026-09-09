import { nextBulkStatus, bulkAllowedActions } from './bulk-lifecycle';

describe('bulk lifecycle', () => {
  it('follows request through final invoice', () => {
    expect(nextBulkStatus('requested', 'quote')).toBe('quoted');
    expect(nextBulkStatus('quoted', 'accept')).toBe('accepted');
    expect(nextBulkStatus('accepted', 'pay_advance')).toBe('advance_paid');
    expect(nextBulkStatus('advance_paid', 'assign')).toBe('assigned');
    expect(nextBulkStatus('assigned', 'trip')).toBe('trip');
    expect(nextBulkStatus('trip', 'invoice')).toBe('invoiced');
    expect(nextBulkStatus('invoiced', 'pay_balance')).toBe('completed');
  });

  it('lets customers accept and pay without quoting themselves', () => {
    expect(bulkAllowedActions('quoted', 'CUSTOMER')).toEqual(expect.arrayContaining(['accept']));
    expect(bulkAllowedActions('requested', 'CUSTOMER')).not.toContain('quote');
  });
});
