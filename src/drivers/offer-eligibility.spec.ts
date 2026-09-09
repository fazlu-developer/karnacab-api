import {
  estimatedEarningsPaise,
  maskCustomer,
  parcelServiceAllowed,
  vehicleMatches,
  withinOfferRadius,
} from './offer-eligibility';

describe('driver offer eligibility', () => {
  it('matches vehicle category when the driver has vehicles', () => {
    expect(vehicleMatches(['SEDAN', 'SUV'], 'SEDAN')).toBe(true);
    expect(vehicleMatches(['BIKE'], 'SEDAN')).toBe(false);
    expect(vehicleMatches([], 'SEDAN')).toBe(true);
  });

  it('requires paid, permitted, compliant parcels and parcel permission', () => {
    const base = {
      parcelEnabled: true,
      status: 'created',
      paymentStatus: 'paid',
      complianceConfirmed: true,
      parcelType: 'documents',
      allowedTypes: ['documents', 'clothes'],
      prohibitedTypes: ['weapons'],
    };
    expect(parcelServiceAllowed(base)).toBe(true);
    expect(parcelServiceAllowed({ ...base, parcelEnabled: false })).toBe(false);
    expect(parcelServiceAllowed({ ...base, paymentStatus: 'unpaid' })).toBe(false);
    expect(parcelServiceAllowed({ ...base, paymentStatus: 'cod' })).toBe(true);
    expect(parcelServiceAllowed({ ...base, parcelType: 'weapons' })).toBe(false);
    expect(parcelServiceAllowed({ ...base, status: 'assigned' })).toBe(false);
  });

  it('uses location radius when both points exist', () => {
    expect(
      withinOfferRadius({
        driverLat: 25.5941,
        driverLng: 85.1376,
        pickupLat: 25.6,
        pickupLng: 85.14,
        radiusKm: 10,
      }),
    ).toBe(true);
    expect(
      withinOfferRadius({
        driverLat: 25.5941,
        driverLng: 85.1376,
        pickupLat: 26.8,
        pickupLng: 84.9,
        radiusKm: 10,
      }),
    ).toBe(false);
    expect(
      withinOfferRadius({
        driverLat: null,
        driverLng: null,
        pickupLat: 26.8,
        pickupLng: 84.9,
        radiusKm: 10,
      }),
    ).toBe(true);
  });

  it('estimates driver earnings after commission', () => {
    expect(estimatedEarningsPaise(21600, 2160)).toBe(19440);
    expect(estimatedEarningsPaise(10000)).toBe(10000);
  });

  it('masks customer identity on the offer card', () => {
    expect(maskCustomer('Rakesh Kumar', '9888888888')).toEqual({
      name: 'Rakesh',
      phoneMasked: '******8888',
    });
  });
});
