import { ForbiddenException } from '@nestjs/common';
import { estimatedEarningsPaise } from '../drivers/offer-eligibility';
import { assertTripPin } from './trip-otp';

describe('trip PIN and transitions', () => {
  it('rejects start or complete without a matching customer PIN', () => {
    expect(() => assertTripPin('start', '1234', undefined)).toThrow(ForbiddenException);
    expect(() => assertTripPin('start', '1234', '0000')).toThrow(ForbiddenException);
    expect(() => assertTripPin('end', '9876', '9876')).not.toThrow();
  });
});

describe('trip earnings invoice', () => {
  it('keeps One-Way 21600 fare with 10% commission as driver earnings 19440', () => {
    expect(estimatedEarningsPaise(21600, 2160)).toBe(19440);
  });
});
