import { Injectable, Logger, OnModuleDestroy } from '@nestjs/common';
import { EventEmitter } from 'events';

export type DomainEventName =
  | 'booking.created'
  | 'booking.lifecycle'
  | 'parcel.created'
  | 'parcel.lifecycle'
  | 'travel.created'
  | 'travel.lifecycle'
  | 'bulk.created'
  | 'bulk.lifecycle'
  | 'corporate.updated'
  | 'payment.captured'
  | 'payment.refunded'
  | 'kyc.submitted'
  | 'kyc.reviewed'
  | 'wallet.posted'
  | 'franchise.approved'
  | 'coupon.applied'
  | 'support.updated'
  | 'ads.reviewed'
  | 'auth.login'
  | 'auth.otp.requested'
  | 'driver.location'
  | 'operator.registered';

@Injectable()
export class DomainEvents implements OnModuleDestroy {
  private readonly logger = new Logger(DomainEvents.name);
  private readonly emitter = new EventEmitter();

  constructor() {
    this.emitter.setMaxListeners(50);
  }

  on<T>(event: DomainEventName, handler: (payload: T) => unknown) {
    this.emitter.on(event, (payload: T) => {
      void Promise.resolve(handler(payload)).catch((error) => {
        this.logger.error({ err: error, event }, 'Domain event handler failed');
      });
    });
  }

  emit<T>(event: DomainEventName, payload: T) {
    this.emitter.emit(event, payload);
  }

  onModuleDestroy() {
    this.emitter.removeAllListeners();
  }
}
