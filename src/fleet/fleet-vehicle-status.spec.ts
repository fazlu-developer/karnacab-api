import { resolveFleetVehicleStatus } from './fleet-vehicle-status';

describe('fleet vehicle status', () => {
  it('keeps maintenance and suspended as fleet locks', () => {
    expect(resolveFleetVehicleStatus({ stored: 'maintenance', hasActiveTrip: true }).status).toBe('maintenance');
    expect(resolveFleetVehicleStatus({ stored: 'suspended', driverOnline: true }).locked).toBe(true);
  });

  it('maps live duty onto available / online / on trip / offline', () => {
    expect(resolveFleetVehicleStatus({ stored: 'offline' }).status).toBe('offline');
    expect(resolveFleetVehicleStatus({ stored: 'offline', assignedDriverId: 1n }).status).toBe('available');
    expect(resolveFleetVehicleStatus({ stored: 'offline', assignedDriverId: 1n, driverOnline: true }).status).toBe(
      'online',
    );
    expect(resolveFleetVehicleStatus({ stored: 'available', hasActiveTrip: true }).status).toBe('on_trip');
  });
});
