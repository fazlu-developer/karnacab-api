import { RideProduct, VehicleCategory } from '@prisma/client';

export const RIDE_TYPES = [
  { key: RideProduct.LOCAL_CAB, label: 'Local Cab' },
  { key: RideProduct.ONE_WAY, label: 'One Way' },
  { key: RideProduct.ROUND_WAY, label: 'Round Way' },
  { key: RideProduct.RENTAL, label: 'Rental' },
  { key: RideProduct.SCHEDULE, label: 'Schedule' },
  { key: RideProduct.OUTSTATION, label: 'Outstation' },
  { key: RideProduct.AIRPORT, label: 'Airport' },
  { key: RideProduct.RAILWAY, label: 'Railway' },
  { key: RideProduct.MULTI_STOP, label: 'Multi-stop' },
] as const;

export const VEHICLE_TYPES = [
  { key: VehicleCategory.BIKE, label: 'Bike', seats: 1 },
  { key: VehicleCategory.AUTO, label: 'Auto', seats: 3 },
  { key: VehicleCategory.E_RICKSHAW, label: 'E-Rickshaw', seats: 3 },
  { key: VehicleCategory.MINI, label: 'Mini', seats: 4 },
  { key: VehicleCategory.SEDAN, label: 'Sedan', seats: 4 },
  { key: VehicleCategory.SUV, label: 'SUV', seats: 6 },
  { key: VehicleCategory.TRAVELLER, label: 'Traveller', seats: 12 },
] as const;

export function rideTypeLabel(product: string) {
  return RIDE_TYPES.find((row) => row.key === product)?.label ?? product;
}

export function vehicleTypeLabel(category: string) {
  return VEHICLE_TYPES.find((row) => row.key === category)?.label ?? category;
}
