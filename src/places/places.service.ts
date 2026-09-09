import { Injectable } from '@nestjs/common';
import { PlaceKind } from '@prisma/client';
import { PrismaService } from '../prisma/prisma.service';
import { CreatePlaceDto } from '../auth/dto/auth.dto';

@Injectable()
export class PlacesService {
  constructor(private readonly prisma: PrismaService) {}

  async recent(userId: bigint) {
    const places = await this.prisma.userPlace.findMany({
      where: { userId, kind: PlaceKind.RECENT },
      orderBy: { createdAt: 'desc' },
      take: 8,
    });
    return {
      recents: places.map((place) => this.serialize(place)),
    };
  }

  async saved(userId: bigint) {
    const places = await this.prisma.userPlace.findMany({
      where: { userId, kind: PlaceKind.SAVED },
      orderBy: { createdAt: 'desc' },
      take: 20,
    });
    return { saved: places.map((place) => this.serialize(place)) };
  }

  async saveNamed(userId: bigint, dto: CreatePlaceDto, kind: PlaceKind) {
    const place = await this.prisma.userPlace.create({
      data: {
        userId,
        kind,
        title: dto.title.trim(),
        subtitle: dto.subtitle?.trim() || null,
        address: dto.address.trim(),
        lat: dto.lat,
        lng: dto.lng,
      },
    });
    return this.serialize(place);
  }

  async remove(userId: bigint, id: bigint) {
    await this.prisma.userPlace.deleteMany({ where: { id, userId } });
    return { ok: true };
  }

  async saveRecent(userId: bigint, dto: CreatePlaceDto) {
    return this.saveNamed(userId, dto, PlaceKind.RECENT);
  }

  async saveCurrentLocation(
    userId: bigint,
    address: string,
    lat: number,
    lng: number,
  ) {
    const [title, ...rest] = address.split(',').map((part) => part.trim());
    await this.prisma.userPlace.create({
      data: {
        userId,
        kind: PlaceKind.RECENT,
        title: title || 'Current location',
        subtitle: rest.join(', ') || null,
        address,
        lat,
        lng,
      },
    });
  }

  private serialize(place: {
    id: bigint;
    title: string;
    subtitle: string | null;
    address: string;
    lat: unknown;
    lng: unknown;
  }) {
    return {
      id: place.id.toString(),
      title: place.title,
      subtitle: place.subtitle,
      address: place.address,
      lat: Number(place.lat),
      lng: Number(place.lng),
    };
  }
}
