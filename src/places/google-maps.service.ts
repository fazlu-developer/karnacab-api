import { Injectable, ServiceUnavailableException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';

@Injectable()
export class GoogleMapsService {
  constructor(private readonly config: ConfigService) {}

  private key() {
    const key = this.config.get<string>('google.mapsApiKey') ?? '';
    if (!key) {
      throw new ServiceUnavailableException('Google Maps API key is not configured');
    }
    return key;
  }

  async autocomplete(query: string, types?: string) {
    const url = new URL('https://maps.googleapis.com/maps/api/place/autocomplete/json');
    url.searchParams.set('input', query);
    url.searchParams.set('key', this.key());
    url.searchParams.set('components', 'country:in');
    url.searchParams.set('language', 'en');
    if (types) {
      url.searchParams.set('types', types);
    }
    const payload = await this.get(url);
    return ((payload.predictions as Array<Record<string, unknown>>) ?? []).map((row) => ({
      placeId: String(row.place_id ?? ''),
      title: String((row.structured_formatting as { main_text?: string } | undefined)?.main_text ?? row.description ?? ''),
      subtitle: String(
        (row.structured_formatting as { secondary_text?: string } | undefined)?.secondary_text ?? '',
      ),
      address: String(row.description ?? ''),
    }));
  }

  async details(placeId: string) {
    const url = new URL('https://maps.googleapis.com/maps/api/place/details/json');
    url.searchParams.set('place_id', placeId);
    url.searchParams.set('fields', 'geometry,formatted_address,name');
    url.searchParams.set('key', this.key());
    const payload = await this.get(url);
    const result = (payload.result ?? {}) as {
      name?: string;
      formatted_address?: string;
      geometry?: { location?: { lat?: number; lng?: number } };
    };
    return {
      placeId,
      title: result.name ?? result.formatted_address ?? '',
      address: result.formatted_address ?? result.name ?? '',
      lat: result.geometry?.location?.lat ?? 0,
      lng: result.geometry?.location?.lng ?? 0,
    };
  }

  async directions(
    originLat: number,
    originLng: number,
    destLat: number,
    destLng: number,
    waypoints?: Array<{ lat: number; lng: number }>,
  ) {
    const points = [
      { lat: originLat, lng: originLng },
      ...(waypoints ?? []),
      { lat: destLat, lng: destLng },
    ];
    const url = new URL('https://maps.googleapis.com/maps/api/directions/json');
    url.searchParams.set('origin', `${originLat},${originLng}`);
    url.searchParams.set('destination', `${destLat},${destLng}`);
    url.searchParams.set('mode', 'driving');
    url.searchParams.set('key', this.key());
    if (waypoints?.length) {
      url.searchParams.set(
        'waypoints',
        waypoints.map((point) => `${point.lat},${point.lng}`).join('|'),
      );
    }
    const payload = await this.get(url);
    const route = ((payload.routes as Array<Record<string, unknown>>) ?? [])[0];
    const legs = ((route?.legs as Array<Record<string, unknown>>) ?? []) as Array<{
      distance?: { value?: number };
      duration?: { value?: number };
    }>;
    let meters = 0;
    let seconds = 0;
    for (const leg of legs) {
      meters += Number(leg.distance?.value ?? 0);
      seconds += Number(leg.duration?.value ?? 0);
    }
    const polyline = String(
      (route?.overview_polyline as { points?: string } | undefined)?.points ?? '',
    );
    const distanceKm =
      meters > 0
        ? Math.round((meters / 1000) * 100) / 100
        : this.pathKm(points);
    return {
      distanceKm,
      durationMinutes: Math.max(1, Math.round(seconds / 60) || Math.max(1, Math.round(distanceKm * 2))),
      polyline,
    };
  }

  pathKm(points: Array<{ lat: number; lng: number }>) {
    let km = 0;
    for (let i = 1; i < points.length; i += 1) {
      km += this.haversineKm(points[i - 1].lat, points[i - 1].lng, points[i].lat, points[i].lng);
    }
    return Math.round(km * 100) / 100;
  }

  haversineKm(lat1: number, lng1: number, lat2: number, lng2: number) {
    const toRad = (value: number) => (value * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLng = toRad(lng2 - lng1);
    const a =
      Math.sin(dLat / 2) ** 2 +
      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2;
    return Math.round(6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)) * 100) / 100;
  }

  private async get(url: URL) {
    const response = await fetch(url);
    const payload = (await response.json()) as Record<string, unknown>;
    const status = String(payload.status ?? '');
    if (status !== 'OK' && status !== 'ZERO_RESULTS') {
      throw new ServiceUnavailableException(
        String(payload.error_message ?? `Google Maps ${status || 'request failed'}`),
      );
    }
    return payload;
  }
}
