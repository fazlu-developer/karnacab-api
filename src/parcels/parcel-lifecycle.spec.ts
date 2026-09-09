import { nextParcelStatus, parcelAllowedActions } from './parcel-lifecycle';

describe('parcel lifecycle', () => {
  it('follows create through delivery', () => {
    expect(nextParcelStatus('created', 'cancel')).toBe('cancelled');
    expect(nextParcelStatus('assigned', 'pickup')).toBe('picked_up');
    expect(nextParcelStatus('picked_up', 'transit')).toBe('in_transit');
    expect(nextParcelStatus('in_transit', 'arrive')).toBe('destination');
    expect(nextParcelStatus('destination', 'out_for_delivery')).toBe('out_for_delivery');
    expect(nextParcelStatus('out_for_delivery', 'deliver')).toBe('delivered');
  });

  it('lets customers cancel only before pickup', () => {
    expect(parcelAllowedActions('created', 'CUSTOMER')).toEqual(['cancel']);
    expect(parcelAllowedActions('assigned', 'CUSTOMER')).toEqual(['cancel']);
    expect(parcelAllowedActions('picked_up', 'CUSTOMER')).toEqual([]);
  });
});
