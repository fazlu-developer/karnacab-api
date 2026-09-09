import { resolveFleetMapStatus } from './fleet-map-status';

describe('fleet map status', () => {
  it('uses the five map statuses: online, offline, on trip, on delivery, maintenance', () => {
    expect(resolveFleetMapStatus({ stored: 'maintenance', hasActiveTrip: true }).status).toBe('maintenance');
    expect(resolveFleetMapStatus({ hasActiveDelivery: true }).status).toBe('on_delivery');
    expect(resolveFleetMapStatus({ hasActiveTrip: true }).status).toBe('on_trip');
    expect(resolveFleetMapStatus({ driverOnline: true }).status).toBe('online');
    expect(resolveFleetMapStatus({ stored: 'available' }).status).toBe('offline');
    expect(resolveFleetMapStatus({ stored: 'suspended', driverOnline: true }).status).toBe('offline');
  });
});
