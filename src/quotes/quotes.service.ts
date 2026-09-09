import { Injectable } from '@nestjs/common';
import { DemoDriverService } from '../drivers/demo-driver.service';
import { GoogleMapsService } from '../places/google-maps.service';
import { FareEngine } from '../ride-engine/fare.engine';
import { VEHICLE_TYPES } from '../ride-engine/ride-catalog';
import { RideQuoteDto } from './dto/ride-quote.dto';
import { RideOptionsDto } from './dto/ride-options.dto';
import { RideProduct, VehicleCategory } from '@prisma/client';

const SERVER_ROUTE_PRODUCTS = new Set<RideProduct>([
  RideProduct.AIRPORT,
  RideProduct.RAILWAY,
  RideProduct.MULTI_STOP,
]);

@Injectable()
export class QuotesService {
  constructor(
    private readonly demoDriver: DemoDriverService,
    private readonly fares: FareEngine,
    private readonly maps: GoogleMapsService,
  ) {}

  async ride(dto: RideQuoteDto) {
    const route = await this.resolveRoute(dto);
    return this.fares.quote({
      ...dto,
      distanceKm: route.distanceKm,
      stopCount: dto.stops?.length ?? dto.stopCount,
    });
  }

  async options(dto: RideOptionsDto) {
    const route = await this.resolveRoute(dto);
    const options = [];
    for (const vehicle of VEHICLE_TYPES) {
      try {
        const quote = await this.fares.quote({
          product: dto.product,
          category: vehicle.key,
          distanceKm: route.distanceKm,
          night: dto.night,
          districtId: dto.districtId,
          hours: dto.hours,
          stopCount: dto.stops?.length ?? dto.stopCount,
          roundTrip: dto.roundTrip,
          waitMinutes: dto.waitMinutes,
          tollPaise: dto.tollPaise,
          parkingPaise: dto.parkingPaise,
          extraHours: dto.extraHours,
          nightStayNights: dto.nightStayNights,
        });
        options.push({
          ...quote,
          polyline: route.polyline,
          label: vehicle.label,
          seats: vehicle.seats,
          etaMinutes: Math.max(3, Math.round(route.distanceKm * 2.1)),
          badge: vehicle.key === VehicleCategory.BIKE ? 'Faster' : vehicle.key === VehicleCategory.SEDAN ? 'Good deal' : null,
          promoRupees: quote.discountRupees,
          listRupees: quote.listRupees,
        });
      } catch {
        continue;
      }
    }
    const nearby =
      dto.pickupLat != null && dto.pickupLng != null
        ? await this.demoDriver.placeNearby(dto.pickupLat, dto.pickupLng, dto.districtId)
        : null;

    return {
      options,
      billedKm: route.distanceKm,
      polyline: route.polyline,
      rentalPackages: dto.product === RideProduct.RENTAL ? await this.fares.rentalPackages(dto.districtId) : undefined,
      promoAppliedRupees: options.reduce((sum, row) => Math.max(sum, Number(row.discountRupees ?? 0)), 0),
      nearbyDriver: nearby
        ? {
            name: nearby.name,
            lat: nearby.lat,
            lng: nearby.lng,
            etaMinutes: 2,
          }
        : null,
    };
  }

  async resolveRoute(dto: {
    product: RideProduct;
    distanceKm: number;
    pickupLat?: number;
    pickupLng?: number;
    dropLat?: number;
    dropLng?: number;
    polyline?: string;
    stops?: Array<{ lat?: number; lng?: number }>;
  }) {
    const fallback = Math.max(1, Math.round(dto.distanceKm));
    if (
      !SERVER_ROUTE_PRODUCTS.has(dto.product) ||
      dto.pickupLat == null ||
      dto.pickupLng == null ||
      dto.dropLat == null ||
      dto.dropLng == null
    ) {
      return { distanceKm: fallback, polyline: dto.polyline };
    }
    const waypoints = (dto.stops ?? []).filter(
      (stop): stop is { lat: number; lng: number } => stop.lat != null && stop.lng != null,
    );
    try {
      const route = await this.maps.directions(
        dto.pickupLat,
        dto.pickupLng,
        dto.dropLat,
        dto.dropLng,
        waypoints,
      );
      return {
        distanceKm: Math.max(1, Math.round(route.distanceKm)),
        polyline: route.polyline || dto.polyline,
      };
    } catch {
      const km = this.maps.pathKm([
        { lat: dto.pickupLat, lng: dto.pickupLng },
        ...waypoints,
        { lat: dto.dropLat, lng: dto.dropLng },
      ]);
      return { distanceKm: Math.max(1, Math.round(km || fallback)), polyline: dto.polyline };
    }
  }
}
