import { Injectable, OnModuleInit } from '@nestjs/common';
import { UserRole } from '@prisma/client';
import { DomainEvents } from '../common/domain-events.service';
import { PrismaService } from '../prisma/prisma.service';
import { NotificationsService } from './notifications.service';

@Injectable()
export class NotificationsListener implements OnModuleInit {
  constructor(
    private readonly events: DomainEvents,
    private readonly prisma: PrismaService,
    private readonly notifications: NotificationsService,
  ) {}

  onModuleInit() {
    this.events.on<{ bookingId: string; customerId: string; product?: string; districtId?: number | null }>(
      'booking.created',
      (payload) => this.onBookingCreated(payload),
    );
    this.events.on<{ bookingId: string; action?: string; lifecycle?: string }>(
      'booking.lifecycle',
      (payload) => this.onBookingLifecycle(payload),
    );
    this.events.on<{ phone: string; code?: string }>('auth.otp.requested', (payload) =>
      this.notifications.dispatch({
        event: 'otp',
        phone: payload.phone,
        vars: { code: payload.code },
      }),
    );
    this.events.on<{ parcelId: string; customerId: string }>('parcel.created', (payload) =>
      this.notifications.dispatch({
        event: 'parcel_update',
        userId: BigInt(payload.customerId),
        vars: { ref: payload.parcelId, status: 'booked' },
        entity: { type: 'parcel', id: payload.parcelId },
      }),
    );
    this.events.on<{ parcelId: string; action?: string; status?: string }>('parcel.lifecycle', (payload) =>
      this.onParcel(payload),
    );
    this.events.on<{ bookingId: string; customerId: string }>('travel.created', (payload) =>
      this.notifications.notify({
        userId: BigInt(payload.customerId),
        title: 'Travel booking',
        body: 'Your KarnaTravel package is reserved.',
        kind: 'travel',
        entity: { type: 'travel', id: payload.bookingId },
      }),
    );
    this.events.on<{ bookingId: string; action?: string; status?: string }>('travel.lifecycle', (payload) =>
      this.onTravel(payload),
    );
    this.events.on<{ bookingId: string; customerId: string }>('bulk.created', (payload) =>
      this.notifications.notify({
        userId: BigInt(payload.customerId),
        title: 'Bulk request received',
        body: 'We will send a quotation shortly.',
        kind: 'bulk_quotation',
        entity: { type: 'bulk', id: payload.bookingId },
      }),
    );
    this.events.on<{ bookingId: string; action?: string; status?: string }>('bulk.lifecycle', (payload) =>
      this.onBulk(payload),
    );
    this.events.on<{ accountId: string; userId: string }>('corporate.updated', (payload) =>
      this.notifications.notify({
        userId: BigInt(payload.userId),
        title: 'Corporate account',
        body: 'Your company profile was updated.',
        kind: 'corporate',
        entity: { type: 'corporate', id: payload.accountId },
      }),
    );
    this.events.on<{ paymentId: string; customerId?: string | null }>('payment.captured', async (payload) => {
      if (!payload.customerId) {
        return;
      }
      await this.notifications.dispatch({
        event: 'payment',
        userId: BigInt(payload.customerId),
        vars: { ref: payload.paymentId },
        entity: { type: 'payment', id: payload.paymentId },
      });
    });
    this.events.on<{ paymentId: string; customerId?: string | null; amountPaise?: number }>(
      'payment.refunded',
      async (payload) => {
        if (!payload.customerId) {
          return;
        }
        await this.notifications.dispatch({
          event: 'refund',
          userId: BigInt(payload.customerId),
          vars: { ref: payload.paymentId, amount: ((payload.amountPaise ?? 0) / 100).toFixed(2) },
          entity: { type: 'payment', id: payload.paymentId },
        });
      },
    );
    this.events.on<{ driverUserId: string; status: string; reason?: string }>('kyc.reviewed', (payload) =>
      this.notifications.dispatch({
        event: payload.status === 'verified' ? 'kyc_approval' : 'kyc_rejection',
        userId: BigInt(payload.driverUserId),
        vars: { reason: payload.reason ?? '' },
        entity: { type: 'kyc', id: payload.driverUserId },
      }),
    );
    this.events.on<{ userId: string; amountPaise: number; direction: string; note?: string; ledgerId: string }>(
      'wallet.posted',
      (payload) =>
        this.notifications.dispatch({
          event: 'wallet_transaction',
          userId: BigInt(payload.userId),
          vars: {
            amount: (payload.amountPaise / 100).toFixed(2),
            direction: payload.direction.toLowerCase(),
            note: payload.note ?? 'Wallet update',
          },
          entity: { type: 'ledger', id: payload.ledgerId },
        }),
    );
    this.events.on<{ franchiseId: string; ownerUserId: string }>('franchise.approved', (payload) =>
      this.notifications.dispatch({
        event: 'franchise_approval',
        userId: BigInt(payload.ownerUserId),
        vars: { ref: payload.franchiseId },
        entity: { type: 'franchise', id: payload.franchiseId },
      }),
    );
    this.events.on<{ userId: string; code?: string; amountPaise?: number; bookingId: string }>('coupon.applied', (payload) =>
      this.notifications.dispatch({
        event: 'coupon',
        userId: BigInt(payload.userId),
        vars: {
          code: payload.code ?? 'coupon',
          amount: ((payload.amountPaise ?? 0) / 100).toFixed(2),
          ref: payload.bookingId,
        },
        entity: { type: 'booking', id: payload.bookingId },
      }),
    );
    this.events.on<{ userId: string; ref?: string; status?: string; ticketId: string }>('support.updated', (payload) =>
      this.notifications.dispatch({
        event: 'support_update',
        userId: BigInt(payload.userId),
        vars: { ref: payload.ref ?? payload.ticketId, status: payload.status ?? 'updated' },
        entity: { type: 'ticket', id: payload.ticketId },
      }),
    );
    this.events.on<{ driverUserId: string }>('kyc.submitted', (payload) =>
      this.notifications.notifyOps({
        title: 'KYC approval needed',
        body: 'A driver submitted documents for review.',
        kind: 'approvals',
        entity: { type: 'kyc', id: payload.driverUserId },
      }),
    );
    this.events.on<{ campaignId: string; advertiserUserId?: string | null; status: string }>(
      'ads.reviewed',
      (payload) => this.onAdReview(payload),
    );
  }

  private async onBookingCreated(payload: {
    bookingId: string;
    customerId: string;
    product?: string;
    districtId?: number | null;
  }) {
    await this.notifications.dispatch({
      event: 'booking_confirmation',
      userId: BigInt(payload.customerId),
      vars: { ref: payload.bookingId },
      title: 'Booking confirmed',
      body: payload.product === 'SCHEDULE' ? 'Your scheduled ride is confirmed.' : 'Your KarnaCab booking is confirmed.',
      entity: { type: 'booking', id: payload.bookingId },
    });
    const drivers = await this.prisma.driver.findMany({
      where: {
        online: true,
        user: {
          status: 'ACTIVE',
          role: UserRole.DRIVER,
          ...(payload.districtId != null ? { districtId: payload.districtId } : {}),
        },
      },
      select: { userId: true },
      take: 12,
    });
    for (const driver of drivers) {
      await this.notifications.notify({
        userId: driver.userId,
        title: 'New ride request',
        body: 'A nearby customer needs a ride.',
        kind: 'new_request',
        entity: { type: 'booking', id: payload.bookingId },
      });
    }
  }

  private async onBookingLifecycle(payload: { bookingId: string; action?: string; lifecycle?: string }) {
    const booking = await this.prisma.booking.findUnique({
      where: { id: BigInt(payload.bookingId) },
      include: {
        driver: { select: { userId: true } },
        vehicle: { select: { fleetOwner: { select: { userId: true } } } },
      },
    });
    if (!booking) {
      return;
    }
    const entity = { type: 'booking', id: payload.bookingId };
    const life = payload.lifecycle ?? '';
    const customer = booking.customerId;
    const driverUserId = booking.driver?.userId;
    if (life === 'driver_assigned' || payload.action === 'assign') {
      await this.notifications.dispatch({
        event: 'driver_assigned',
        userId: customer,
        vars: { ref: booking.publicRef },
        entity,
      });
      if (booking.vehicle?.fleetOwner?.userId) {
        await this.notifications.notify({
          userId: booking.vehicle.fleetOwner.userId,
          title: 'Driver assigned',
          body: `Trip ${booking.publicRef} was assigned.`,
          kind: 'assignments',
          entity,
        });
      }
    }
    if (payload.action === 'arriving' || life === 'driver_arriving') {
      await this.notifications.dispatch({
        event: 'driver_arriving',
        userId: customer,
        entity,
      });
    }
    if (payload.action === 'start' || life === 'started') {
      await this.notifications.dispatch({
        event: 'ride_started',
        userId: customer,
        entity,
      });
    }
    if (payload.action === 'complete' || life === 'completed') {
      await this.notifications.dispatch({
        event: 'ride_completed',
        userId: customer,
        entity,
      });
      if (driverUserId) {
        await this.notifications.notify({
          userId: driverUserId,
          title: 'Trip completed',
          body: 'Earnings were posted to your wallet.',
          kind: 'earnings',
          entity,
        });
      }
    }
    if (payload.action === 'cancel' || life === 'cancelled') {
      await this.notifications.dispatch({
        event: 'cancellation',
        userId: customer,
        vars: { ref: booking.publicRef },
        entity,
      });
      if (driverUserId) {
        await this.notifications.notify({
          userId: driverUserId,
          title: 'Booking cancelled',
          body: 'The customer ride was cancelled.',
          kind: 'booking_cancelled',
          entity,
        });
      }
    }
    if (payload.action === 'reschedule') {
      await this.notifications.notify({
        userId: customer,
        title: 'Pickup reminder',
        body: 'Your scheduled ride time was updated.',
        kind: 'booking',
        entity,
      });
      if (driverUserId) {
        await this.notifications.notify({
          userId: driverUserId,
          title: 'Trip reminder',
          body: 'A scheduled ride was moved. Check your jobs.',
          kind: 'trip_reminder',
          entity,
        });
      }
    }
  }

  private async onParcel(payload: { parcelId: string; action?: string; status?: string }) {
    const row = await this.prisma.parcelShipment.findUnique({
      where: { id: BigInt(payload.parcelId) },
      select: { customerId: true, driver: { select: { userId: true } } },
    });
    if (!row) {
      return;
    }
    await this.notifications.dispatch({
      event: 'parcel_update',
      userId: row.customerId,
      vars: { ref: payload.parcelId, status: payload.status ?? payload.action ?? 'updated' },
      entity: { type: 'parcel', id: payload.parcelId },
    });
  }

  private async onTravel(payload: { bookingId: string; action?: string; status?: string }) {
    const row = await this.prisma.travelBooking.findUnique({
      where: { id: BigInt(payload.bookingId) },
      select: { customerId: true },
    });
    if (!row) {
      return;
    }
    await this.notifications.notify({
      userId: row.customerId,
      title: 'Travel update',
      body: `Status: ${payload.status ?? payload.action ?? 'updated'}`,
      kind: 'travel',
      entity: { type: 'travel', id: payload.bookingId },
    });
  }

  private async onBulk(payload: { bookingId: string; action?: string; status?: string }) {
    const row = await this.prisma.bulkBooking.findUnique({
      where: { id: BigInt(payload.bookingId) },
      select: { customerId: true },
    });
    if (!row) {
      return;
    }
    const quoted = payload.action === 'quote' || payload.status === 'quoted';
    await this.notifications.notify({
      userId: row.customerId,
      title: quoted ? 'Bulk quotation ready' : 'Bulk booking update',
      body: quoted ? 'Review and accept the quotation in Bulk.' : `Status: ${payload.status ?? payload.action}`,
      kind: 'bulk_quotation',
      entity: { type: 'bulk', id: payload.bookingId },
    });
  }

  private async onAdReview(payload: { campaignId: string; advertiserUserId?: string | null; status: string }) {
    if (payload.advertiserUserId) {
      await this.notifications.notify({
        userId: BigInt(payload.advertiserUserId),
        title: payload.status === 'rejected' ? 'Ad campaign rejected' : 'Ad campaign approved',
        body: 'Open KarnaCab Ads for details.',
        kind: 'offers',
        entity: { type: 'ad', id: payload.campaignId },
      });
    }
    await this.notifications.notifyOps({
      title: 'Ad review complete',
      body: `Campaign ${payload.status}.`,
      kind: 'approvals',
      entity: { type: 'ad', id: payload.campaignId },
    });
  }
}
