import { Injectable, OnModuleInit } from '@nestjs/common';
import { AuditService } from './audit.service';
import { DomainEvents } from './domain-events.service';

@Injectable()
export class AuditListener implements OnModuleInit {
  constructor(
    private readonly events: DomainEvents,
    private readonly audit: AuditService,
  ) {}

  onModuleInit() {
    this.events.on<{
      bookingId: string;
      customerId: string;
      product: string;
    }>('booking.created', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.customerId),
        domain: 'bookings',
        action: 'created',
        entityType: 'booking',
        entityId: payload.bookingId,
        payload: { product: payload.product },
      }),
    );

    this.events.on<{
      bookingId: string;
      action: string;
      lifecycle: string;
    }>('booking.lifecycle', (payload) =>
      this.audit.record({
        domain: 'bookings',
        action: payload.action,
        entityType: 'booking',
        entityId: payload.bookingId,
        payload: { lifecycle: payload.lifecycle },
      }),
    );

    this.events.on<{ parcelId: string; customerId: string }>('parcel.created', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.customerId),
        domain: 'parcels',
        action: 'created',
        entityType: 'parcel',
        entityId: payload.parcelId,
      }),
    );

    this.events.on<{ parcelId: string; action: string; status: string }>('parcel.lifecycle', (payload) =>
      this.audit.record({
        domain: 'parcels',
        action: payload.action,
        entityType: 'parcel',
        entityId: payload.parcelId,
        payload: { status: payload.status },
      }),
    );

    this.events.on<{ bookingId: string; customerId: string }>('travel.created', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.customerId),
        domain: 'travel',
        action: 'created',
        entityType: 'travel_booking',
        entityId: payload.bookingId,
      }),
    );

    this.events.on<{ bookingId: string; action: string; status: string }>('travel.lifecycle', (payload) =>
      this.audit.record({
        domain: 'travel',
        action: payload.action,
        entityType: 'travel_booking',
        entityId: payload.bookingId,
        payload: { status: payload.status },
      }),
    );

    this.events.on<{ bookingId: string; customerId: string }>('bulk.created', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.customerId),
        domain: 'bookings',
        action: 'created',
        entityType: 'bulk_booking',
        entityId: payload.bookingId,
      }),
    );

    this.events.on<{ bookingId: string; action: string; status: string }>('bulk.lifecycle', (payload) =>
      this.audit.record({
        domain: 'bookings',
        action: payload.action,
        entityType: 'bulk_booking',
        entityId: payload.bookingId,
        payload: { status: payload.status },
      }),
    );

    this.events.on<{ accountId: string; userId: string }>('corporate.updated', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.userId),
        domain: 'corporate',
        action: 'updated',
        entityType: 'corporate_account',
        entityId: payload.accountId,
      }),
    );

    this.events.on<{ userId: string; role: string }>('auth.login', (payload) =>
      this.audit.record({
        actorUserId: BigInt(payload.userId),
        domain: 'users',
        action: 'login',
        entityType: 'user',
        entityId: payload.userId,
        payload: { role: payload.role },
      }),
    );
  }
}
