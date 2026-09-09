import { BookingStatus } from '@prisma/client';
import {
  canReceiveOffers,
  offerBlockReason,
  persistFromDuty,
  resolveDutyStatus,
} from './driver-duty';

describe('driver duty / offer gate', () => {
  const base = {
    storedDuty: 'online',
    onlineFlag: true,
    userStatus: 'ACTIVE',
    vehicleStatuses: ['offline'],
    canGoOnline: true,
    hasActiveRide: false,
    hasActiveParcel: false,
  };

  it('allows offers only when duty is online and KYC is clear', () => {
    const duty = resolveDutyStatus(base);
    expect(duty).toBe('online');
    expect(canReceiveOffers(duty, true)).toBe(true);
    expect(offerBlockReason(duty, true)).toBeNull();
  });

  it('blocks offers while on a trip', () => {
    const duty = resolveDutyStatus({ ...base, hasActiveRide: true });
    expect(duty).toBe('on_trip');
    expect(canReceiveOffers(duty, true)).toBe(false);
    expect(offerBlockReason(duty, true)).toMatch(/current trip/i);
  });

  it('blocks offers when suspended', () => {
    const duty = resolveDutyStatus({ ...base, userStatus: 'SUSPENDED' });
    expect(duty).toBe('suspended');
    expect(canReceiveOffers(duty, true)).toBe(false);
  });

  it('blocks offers during vehicle maintenance', () => {
    const duty = resolveDutyStatus({ ...base, vehicleStatuses: ['maintenance'] });
    expect(duty).toBe('maintenance');
    expect(canReceiveOffers(duty, true)).toBe(false);
  });

  it('forces offline when required documents are expired', () => {
    const duty = resolveDutyStatus({ ...base, canGoOnline: false });
    expect(duty).toBe('offline');
    expect(canReceiveOffers(duty, false)).toBe(false);
    expect(offerBlockReason(duty, false)).toMatch(/expired/i);
  });

  it('persists online flag only for online duty', () => {
    expect(persistFromDuty('online').online).toBe(true);
    expect(persistFromDuty('on_trip').online).toBe(false);
    expect(BookingStatus.ONGOING).toBeDefined();
  });
});
