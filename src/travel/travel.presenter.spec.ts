import { dateAllowed } from './travel-categories';
import { presentTravelPackage } from './travel.presenter';
import { PackageStatus } from '@prisma/client';

describe('KarnaTravel package presenter', () => {
  it('exposes server price in rupees from package row', () => {
    const view = presentTravelPackage({
      id: 1,
      districtId: 1,
      category: 'DARSHAN_YATRA',
      title: 'Patna city darshan',
      destination: 'Patna',
      places: 'Golghar',
      durationHours: 8,
      durationLabel: '1 day',
      kmIncluded: 80,
      vehicleLabel: 'Sedan',
      driverLabel: 'Dedicated driver',
      pricePaise: 450000,
      inclusions: 'Cab',
      exclusions: 'Meals',
      itinerary: [{ day: 1, title: 'City', detail: 'Loop' }],
      gallery: [],
      availableDates: ['2026-10-03'],
      status: PackageStatus.PUBLISHED,
    });
    expect(view.priceRupees).toBe(4500);
    expect(view.categoryLabel).toBe('Darshan/Yatra');
    expect(view.itinerary).toHaveLength(1);
  });

  it('only allows listed dates when the package has a calendar', () => {
    expect(dateAllowed(['2026-10-03'], '2026-10-03')).toBe(true);
    expect(dateAllowed(['2026-10-03'], '2026-10-04')).toBe(false);
    expect(dateAllowed([], '2026-12-01')).toBe(true);
  });
});
