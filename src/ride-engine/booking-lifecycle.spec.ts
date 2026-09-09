import { BookingStatus } from '@prisma/client';
import {
  adminTripTrack,
  nextLifecycle,
  toLegacyStatus,
  toLifecycle,
  isOpenOffer,
} from './booking-lifecycle';

describe('booking lifecycle', () => {
  it('maps legacy REQUESTED/ASSIGNED/ONGOING into the unified lifecycle', () => {
    expect(toLifecycle('REQUESTED')).toBe('driver_searching');
    expect(toLifecycle('ASSIGNED')).toBe('driver_assigned');
    expect(toLifecycle('ONGOING')).toBe('started');
    expect(toLegacyStatus('driver_assigned')).toBe(BookingStatus.ASSIGNED);
    expect(toLegacyStatus('started')).toBe(BookingStatus.ONGOING);
  });

  it('keeps One-Way and Local Cab on the same state machine', () => {
    expect(nextLifecycle('REQUESTED', 'cancel')).toBe('cancelled');
    expect(nextLifecycle('ASSIGNED', 'arriving')).toBe('driver_arriving');
    expect(nextLifecycle('DRIVER_ARRIVED', 'start')).toBe('started');
    expect(nextLifecycle('STARTED', 'complete')).toBe('completed');
  });

  it('blocks skipping pickup arrival or completing without a started trip', () => {
    expect(() => nextLifecycle('ASSIGNED', 'start')).toThrow();
    expect(() => nextLifecycle('DRIVER_ARRIVING', 'start')).toThrow();
    expect(() => nextLifecycle('DRIVER_ASSIGNED', 'complete')).toThrow();
    expect(() => nextLifecycle('DRIVER_ARRIVED', 'complete')).toThrow();
  });

  it('exposes the management trip path including OTP and in-progress', () => {
    const requested = adminTripTrack('REQUESTED');
    expect(requested.cancelled).toBe(false);
    expect(requested.steps.find((step) => step.key === 'requested')?.active).toBe(true);
    const started = adminTripTrack('STARTED');
    expect(started.steps.find((step) => step.key === 'otp')?.done || started.steps.find((step) => step.key === 'otp')?.active).toBe(true);
    expect(started.steps.find((step) => step.key === 'progress')?.active).toBe(true);
    expect(adminTripTrack('CANCELLED').path).toEqual(['Requested', 'Cancelled']);
  });

  it('treats REQUESTED and DRIVER_SEARCHING as open driver offers', () => {
    expect(isOpenOffer('REQUESTED', null)).toBe(true);
    expect(isOpenOffer('DRIVER_SEARCHING', null)).toBe(true);
    expect(isOpenOffer('ASSIGNED', 1n)).toBe(false);
  });
});
