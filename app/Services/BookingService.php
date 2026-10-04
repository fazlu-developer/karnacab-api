<?php

namespace App\Services;

use App\Mail\EventNoticeMail;
use App\Mail\TripInvoiceMail;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use App\Support\BookingProductSchema;
use App\Support\BookingStatus;
use App\Support\Geo;
use App\Support\ServiceArea;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BookingService
{
    public function __construct(
        private readonly FareService $fares,
        private readonly NearbyDriverService $nearby,
        private readonly RideSettingsService $settings,
        private readonly FcmPushService $push,
    ) {}

    public function create(User $actor, array $dto): array
    {
        abort_unless(in_array($actor->role, ['CUSTOMER', 'CORPORATE', 'ADMIN', 'SUPER_ADMIN'], true), 403, 'Only customers can request a ride');
        BookingProductSchema::ensure();
        $pickupLat = isset($dto['pickupLat']) ? (float) $dto['pickupLat'] : null;
        $pickupLng = isset($dto['pickupLng']) ? (float) $dto['pickupLng'] : null;
        $dropLat = isset($dto['dropLat']) ? (float) $dto['dropLat'] : null;
        $dropLng = isset($dto['dropLng']) ? (float) $dto['dropLng'] : null;
        abort_unless($pickupLat !== null && $pickupLng !== null, 422, 'Pickup coordinates are required');
        abort_unless($dropLat !== null && $dropLng !== null, 422, 'Drop coordinates are required');
        ServiceArea::assertTrip($dto + [
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
            'dropLat' => $dropLat,
            'dropLng' => $dropLng,
            'pickupText' => $dto['pickupText'] ?? $dto['pickup_text'] ?? null,
            'dropText' => $dto['dropText'] ?? $dto['drop_text'] ?? null,
        ]);

        $distanceKm = (float) ($dto['distanceKm'] ?? Geo::haversineKm($pickupLat, $pickupLng, $dropLat, $dropLng));
        $dto['product'] = FareService::cityOrOutstation((string) ($dto['product'] ?? 'LOCAL_CAB'), $distanceKm);
        $quote = $this->fares->quote([
            'product' => $dto['product'],
            'category' => $dto['category'],
            'distanceKm' => $distanceKm,
            'districtId' => $dto['districtId'] ?? $actor->district_id,
            'hours' => $dto['hours'] ?? null,
            'night' => $dto['night'] ?? false,
            'stopCount' => isset($dto['stops']) ? count($dto['stops']) : ($dto['stopCount'] ?? 0),
            'roundTrip' => $dto['roundTrip'] ?? true,
            'waitMinutes' => $dto['waitMinutes'] ?? 0,
            'tollPaise' => $dto['tollPaise'] ?? 0,
            'parkingPaise' => $dto['parkingPaise'] ?? 0,
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
        ]);
        $couponCode = strtoupper(trim((string) ($dto['couponCode'] ?? $dto['coupon_code'] ?? '')));
        if ($couponCode !== '') {
            try {
                $preview = app(AppSurfaceService::class)->previewCoupon($actor, [
                    'code' => $couponCode,
                    'farePaise' => (int) ($quote['totalPaise'] ?? 0),
                ]);
                $quote['totalPaise'] = (int) ($preview['payablePaise'] ?? $quote['totalPaise']);
                $quote['coupon'] = $preview;
            } catch (\Throwable) {
                abort(422, 'Coupon could not be applied.');
            }
        }

        $timeout = 600;
        $radius = $this->settings->radiusKm();
        $product = strtoupper((string) ($dto['product'] ?? 'LOCAL_CAB'));
        $driverDispatch = self::driverCanAccept($product);
        if ($product === 'RENTAL' && empty($dto['scheduledAt'])) {
            $dto['scheduledAt'] = now()->addHour()->toIso8601String();
        }
        $status = $driverDispatch ? BookingStatus::SEARCHING : 'CONFIRMED';

        $matches = $driverDispatch ? $this->nearby->search(
            $pickupLat,
            $pickupLng,
            (string) $dto['category'],
            $radius,
            false,
            isset($dto['districtId']) ? (int) $dto['districtId'] : ($actor->district_id ? (int) $actor->district_id : null),
            (string) ($dto['product'] ?? 'LOCAL_CAB'),
        ) : [];
        $mode = $this->normalizePaymentMode($dto['paymentMode'] ?? $dto['payment_mode'] ?? 'CASH');
        if ($mode === 'WALLET') {
            $balance = app(CustomerWallet::class)->balancePaise((int) $actor->id);
            abort_unless($balance >= (int) $quote['totalPaise'], 422, 'Add wallet balance to pay with wallet.');
        }

        $booking = Booking::query()->create($this->filterColumns([
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'district_id' => $dto['districtId'] ?? $actor->district_id,
            'product' => $dto['product'],
            'category' => $dto['category'],
            'status' => $status,
            'search_expires_at' => $driverDispatch ? now()->addSeconds($timeout) : null,
            'pickup_text' => $dto['pickupText'] ?? $dto['pickup'] ?? 'Pickup',
            'drop_text' => $dto['dropText'] ?? $dto['drop'] ?? 'Drop',
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'drop_lat' => $dropLat,
            'drop_lng' => $dropLng,
            'distance_km' => $quote['billedKm'] ?? $distanceKm,
            'quote_paise' => $quote['totalPaise'],
            'quote_snapshot' => array_merge($quote, [
                'bookedDistanceKm' => $distanceKm,
                'fareInputs' => [
                    'hours' => $dto['hours'] ?? null,
                    'extraHours' => $dto['extraHours'] ?? 0,
                    'stopCount' => isset($dto['stops']) ? count($dto['stops']) : ($dto['stopCount'] ?? 0),
                    'roundTrip' => $dto['roundTrip'] ?? ($dto['product'] === 'ROUND_WAY'),
                    'nightStayNights' => $dto['nightStayNights'] ?? 0,
                    'waitMinutes' => $dto['waitMinutes'] ?? 0,
                    'night' => $dto['night'] ?? false,
                    'tollPaise' => $dto['tollPaise'] ?? 0,
                    'parkingPaise' => $dto['parkingPaise'] ?? 0,
                ],
            ]),
            'scheduled_at' => $dto['scheduledAt'] ?? null,
            'return_at' => $dto['returnAt'] ?? null,
            'flight_number' => $dto['flightNumber'] ?? null,
            'train_number' => $dto['trainNumber'] ?? null,
            'terminal' => $dto['terminal'] ?? null,
            'passenger_name' => $dto['passengerName'] ?? $actor->name,
            'passenger_phone' => $dto['passengerPhone'] ?? $actor->phone,
            'booked_for_other' => (bool) ($dto['bookForOther'] ?? $dto['bookedForOther'] ?? false),
            'instructions' => $dto['instructions'] ?? null,
            'start_otp' => (string) random_int(1000, 9999),
            'end_otp' => (string) random_int(1000, 9999),
            'payment_mode' => $mode,
        ]));

        $this->storeStops($booking, is_array($dto['stops'] ?? null) ? $dto['stops'] : []);

        Log::info('booking.created', [
            'bookingId' => $booking->id,
            'status' => $booking->status,
            'nearby' => count($matches),
            'radiusKm' => $radius,
        ]);

        $presented = $this->present($booking->fresh(), $actor);
        $opsTitle = $driverDispatch
            ? 'New ride request'
            : 'Assign a driver · '.str_replace('_', ' ', $product);
        app()->terminating(function () use ($booking, $matches, $driverDispatch, $opsTitle) {
            try {
                if ($driverDispatch && $matches !== []) {
                    $this->writeOffers($booking, $matches);
                    $this->push->notifyUsers(
                        array_map(fn ($row) => (int) ($row['userId'] ?? 0), $matches),
                        'New KarnaRide booking',
                        trim(($booking->pickup_text ?: 'Pickup').' → '.($booking->drop_text ?: 'Drop')),
                        [
                            'type' => 'booking_offer',
                            'event' => 'new_booking',
                            'bookingId' => (string) $booking->id,
                        ],
                    );
                }
                $this->notifyOps($booking, $opsTitle);
            } catch (\Throwable $e) {
                Log::warning('booking.notify_failed', [
                    'bookingId' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $presented;
    }

    public function list(User $actor)
    {
        $this->expireSearching();
        $q = Booking::query()->orderByDesc('id');
        if ($actor->role === 'CUSTOMER' || $actor->role === 'CORPORATE') {
            $q->where('customer_id', $actor->id);
        } elseif ($actor->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $actor->id)->first();
            abort_unless($driver, 403, 'Driver profile required');
            $offered = $this->offeredBookingIds((int) $driver->id);
            $q->where(function ($inner) use ($driver, $offered) {
                $inner->where('driver_id', $driver->id);
                if ($offered) {
                    $inner->orWhereIn('id', $offered);
                }
            });
        }

        return $q->limit(100)->get()->map(fn ($row) => $this->present($row, $actor))->values()->all();
    }

    public function one(User $actor, string $id): array
    {
        $this->expireSearching();

        return $this->present($this->findVisible($actor, $id), $actor);
    }

    public function live(User $actor, string $id): array
    {
        $booking = $this->findVisible($actor, $id);
        $presented = $this->present($booking, $actor);
        $driverUser = null;
        if ($booking->driver_id) {
            $driver = Driver::query()->with('user')->find($booking->driver_id);
            $driverUser = $driver?->user;
        }

        return [
            'booking' => $presented,
            'driver' => $presented['driver'] ?? null,
            'location' => $driverUser && $driverUser->last_lat !== null ? [
                'lat' => (float) $driverUser->last_lat,
                'lng' => (float) $driverUser->last_lng,
                'heading' => $driverUser->last_heading ?? null,
                'recordedAt' => optional($driverUser->location_updated_at)?->toIso8601String(),
                'stale' => $driverUser->location_updated_at
                    ? $driverUser->location_updated_at->lt(now()->subSeconds($this->settings->locationStaleSeconds()))
                    : true,
            ] : null,
        ];
    }

    public function accept(User $actor, string $id): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only drivers can accept bookings');
        $this->expireSearching((int) $id);
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->firstOrFail();
        $busy = Booking::query()->where('driver_id', $driver->id)->whereIn('status', BookingStatus::openForDriver())->exists();
        abort_if($busy, 422, 'Finish your current trip before accepting another booking.');
        $duty = strtolower((string) ($driver->duty_status ?? 'online'));
        if ($duty === 'on_trip' || $duty === 'busy' || $duty === '') {
            $driver->update(['duty_status' => 'online', 'online' => 1]);
            $driver->refresh();
        }
        abort_unless((bool) $driver->online, 422, 'Go online before accepting bookings.');
        abort_unless(in_array(strtolower((string) $driver->duty_status), ['online', 'available'], true), 422, 'Go online before accepting bookings.');

        $preview = Booking::query()->find($id);
        abort_unless($preview, 404, 'Booking not found');
        $vehicles = $driver->vehicles;
        if ($vehicles->isEmpty()) {
            $vehicles = \App\Models\Vehicle::query()->where('driver_id', $driver->id)->get();
        }
        $wanted = strtoupper((string) $preview->category);
        $vehicle = $vehicles->first(fn ($row) => strtoupper((string) $row->category) === $wanted) ?? $vehicles->first();
        abort_unless($vehicle, 422, 'Add a vehicle before accepting rides.');
        $wallet = Schema::hasTable('wallets')
            ? DB::table('wallets')->where('owner_user_id', $actor->id)->where(function ($q) {
                if (Schema::hasColumn('wallets', 'owner_type')) {
                    $q->where('owner_type', 'DRIVER');
                }
            })->orderBy('id')->first()
            : null;
        $eligibility = $this->settings->walletEligibility((int) ($wallet->balance_paise ?? 0), (int) ($preview->quote_paise ?? 0));
        abort_unless($eligibility['eligible'], 422, $eligibility['reason'] ?? 'Wallet balance is too low to accept this booking.');

        $assigned = false;
        DB::transaction(function () use ($id, $driver, $vehicle, &$assigned) {
            $booking = Booking::query()->where('id', $id)->lockForUpdate()->first();
            abort_unless($booking, 404, 'Booking not found');
            abort_unless(BookingStatus::isSearching((string) $booking->status) && ! $booking->driver_id, 409, 'Booking has already been accepted by another driver.');
            abort_unless(strcasecmp((string) $vehicle->category, (string) $booking->category) === 0, 422, 'Vehicle category does not match this booking.');

            $updated = Booking::query()
                ->where('id', $booking->id)
                ->whereNull('driver_id')
                ->whereIn('status', BookingStatus::searching())
                ->update($this->filterColumns([
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'status' => BookingStatus::DRIVER_ACCEPTED,
                ]));
            abort_unless($updated === 1, 409, 'Booking has already been accepted by another driver.');
            $driver->update(['duty_status' => 'on_trip']);
            $this->markRequest((int) $booking->id, (int) $driver->id, 'ACCEPTED');
            $this->closeOtherOffers((int) $booking->id, (int) $driver->id);
            $assigned = true;
        });

        abort_unless($assigned, 409, 'Booking has already been accepted by another driver.');
        $booking = Booking::query()->findOrFail($id);
        Log::info('booking.accepted', ['bookingId' => $booking->id, 'driverId' => $driver->id]);
        $presented = $this->present($booking, $actor);
        $noticeBooking = $booking;
        $driverName = $actor->name ?: 'Your driver';
        app()->terminating(function () use ($noticeBooking, $driverName) {
            try {
                $this->notifyOps($noticeBooking, 'Driver found for booking '.$noticeBooking->public_ref);
                $notice = app(NotificationTemplates::class)->definition('driver_assigned', [
                    'title' => 'Your booking is accepted',
                    'body' => '{name} accepted your booking {ref}.',
                    'channels' => ['in_app', 'push'],
                ]);
                $vars = [
                    'name' => $driverName,
                    'ref' => (string) $noticeBooking->public_ref,
                    'status' => 'accepted',
                ];
                $title = NotificationTemplates::fill($notice['title'], $vars);
                $body = NotificationTemplates::fill($notice['body'], $vars);
                if (in_array('in_app', $notice['channels'], true) || in_array('push', $notice['channels'], true)) {
                    $this->push->notifyUsers(
                        [(int) $noticeBooking->customer_id],
                        $title !== '' ? $title : 'Your booking is accepted',
                        $body !== '' ? $body : 'Your booking is accepted.',
                        ['type' => 'trip', 'event' => 'accepted', 'silent' => '1', 'bookingId' => (string) $noticeBooking->id],
                    );
                }
                if (in_array('email', $notice['channels'], true)) {
                    $this->emailCustomer((int) $noticeBooking->customer_id, $title, $body);
                }
            } catch (\Throwable $e) {
                Log::warning('booking.accept_notify_failed', ['bookingId' => $noticeBooking->id, 'error' => $e->getMessage()]);
            }
        });

        return $presented;
    }

    public function reject(User $actor, string $id): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only drivers can reject bookings');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $booking = Booking::query()->find($id);
        if (! $booking || (string) $booking->status !== BookingStatus::SEARCHING || $booking->driver_id) {
            return ['available' => false, 'id' => (string) $id];
        }
        $this->ensureOfferColumns();
        $now = now();
        $row = Schema::hasTable('booking_driver_requests')
            ? DB::table('booking_driver_requests')->where('booking_id', $booking->id)->where('driver_id', $driver->id)->first()
            : null;
        $count = ((int) ($row->reject_count ?? 0)) + 1;
        $final = $count >= 2;
        if (Schema::hasTable('booking_driver_requests')) {
            DB::table('booking_driver_requests')->updateOrInsert(
                ['booking_id' => $booking->id, 'driver_id' => $driver->id],
                [
                    'user_id' => $actor->id,
                    'status' => $final ? 'REJECTED' : 'SNOOZED',
                    'reject_count' => $count,
                    'snooze_until' => $final ? null : $now->copy()->addMinutes(2),
                    'responded_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $row->created_at ?? $now,
                ],
            );
        }

        return $this->present($booking, $actor);
    }

    public static function driverCanAccept(string $product): bool
    {
        return in_array(strtoupper($product), ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'SCHEDULE'], true);
    }

    public function cancel(User $actor, string $id, array $data = []): array
    {
        return $this->lifecycle($actor, $id, 'cancel', null, $data);
    }

    public function rate(User $actor, string $id, int $stars, string $comment): void
    {
        $booking = $this->findVisible($actor, $id);
        $stars = max(1, min(5, $stars));
        if (Schema::hasTable('booking_ratings')) {
            if (! Schema::hasColumn('booking_ratings', 'comment')) {
                try {
                    Schema::table('booking_ratings', fn ($table) => $table->text('comment')->nullable());
                } catch (\Throwable) {
                }
            }
            $row = [
                'booking_id' => $booking->id,
                'from_role' => $actor->role,
                'from_user_id' => $actor->id,
                'stars' => $stars,
                'comment' => $comment !== '' ? $comment : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $match = ['booking_id' => $booking->id];
            if (Schema::hasColumn('booking_ratings', 'from_role')) {
                $match['from_role'] = $actor->role;
            }
            DB::table('booking_ratings')->updateOrInsert(
                $match,
                array_filter($row, fn ($key) => Schema::hasColumn('booking_ratings', $key), ARRAY_FILTER_USE_KEY),
            );
        }
        if ($booking->driver_id && Schema::hasTable('booking_ratings')) {
            $ids = Booking::query()->where('driver_id', $booking->driver_id)->pluck('id');
            $avg = $ids->isEmpty() ? null : DB::table('booking_ratings')->whereIn('booking_id', $ids)->avg('stars');
            if ($avg !== null) {
                Driver::query()->where('id', $booking->driver_id)->update(['rating_avg' => round((float) $avg, 2)]);
            }
        }
    }

    public function verifyOtp(User $actor, string $id, string $otp): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only the assigned driver can verify OTP');
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($booking->status === BookingStatus::DRIVER_ARRIVED, 422, 'Arrive at pickup before verifying OTP.');
        abort_unless(hash_equals((string) $booking->start_otp, trim($otp)), 422, 'Invalid OTP');
        abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::TRIP_STARTED)
            || BookingStatus::canTransition((string) $booking->status, BookingStatus::OTP_VERIFIED), 422, 'Invalid booking status');
        $booking->update($this->filterColumns([
            'status' => BookingStatus::TRIP_STARTED,
            'otp_verified_at' => now(),
            'trip_started_at' => now(),
        ]));
        Log::info('booking.otp_verified', ['bookingId' => $booking->id]);

        return $this->present($booking->fresh(), $actor);
    }

    public function lifecycle(User $actor, string $id, string $status, ?string $otp = null, array $extra = []): array
    {
        $action = strtolower($status);
        $booking = $this->findVisible($actor, $id);

        if (in_array($action, ['cancel', 'cancelled', 'customer_cancelled', 'driver_cancelled'], true)) {
            $target = $actor->role === 'DRIVER' ? BookingStatus::DRIVER_CANCELLED : BookingStatus::CUSTOMER_CANCELLED;
            if (BookingStatus::isTerminal((string) $booking->status)) {
                return $this->present($booking, $actor);
            }
            $reason = trim((string) ($extra['reason'] ?? $extra['cancelReason'] ?? $extra['cancel_reason'] ?? ''));
            $custom = trim((string) ($extra['customReason'] ?? $extra['note'] ?? ''));
            $fullReason = trim($reason.($custom !== '' ? ' — '.$custom : ''));
            $booking->update($this->filterColumns([
                'status' => $target,
                'cancel_reason' => $fullReason !== '' ? $fullReason : null,
                'cancelled_by' => $actor->role,
            ]));
            $this->closeAllOffers((int) $booking->id, 'CANCELLED');
            if ($booking->driver_id) {
                Driver::query()->where('id', $booking->driver_id)->update(['duty_status' => 'online', 'online' => 1]);
            }
            Log::info('booking.cancelled', ['bookingId' => $booking->id, 'by' => $actor->role, 'reason' => $fullReason]);
            $targets = [(int) $booking->customer_id];
            if ($booking->driver_id) {
                $driverUserId = Driver::query()->where('id', $booking->driver_id)->value('user_id');
                if ($driverUserId) {
                    $targets[] = (int) $driverUserId;
                }
            }
            $this->push->notifyUsers(
                $targets,
                'Booking cancelled',
                $fullReason !== '' ? $fullReason : 'This trip was cancelled.',
                ['type' => 'trip', 'event' => 'cancelled', 'bookingId' => (string) $booking->id],
            );

            return $this->present($booking->fresh(), $actor);
        }

        if (in_array($action, ['arrive', 'driver_arrived', 'arrived'], true)) {
            $booking = $this->assignedToDriver($actor, $id);
            abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::DRIVER_ARRIVED), 422, 'Invalid booking status');
            $booking->update($this->filterColumns([
                'status' => BookingStatus::DRIVER_ARRIVED,
                'arrived_at' => now(),
            ]));
            $this->push->notifyUsers(
                [(int) $booking->customer_id],
                'Driver has arrived',
                'Your driver is at the pickup point.',
                ['type' => 'trip', 'event' => 'arrived', 'bookingId' => (string) $booking->id],
            );

            return $this->present($booking->fresh(), $actor);
        }

        if (in_array($action, ['verify_otp', 'otp', 'start'], true)) {
            if ($otp) {
                return $this->verifyOtp($actor, $id, $otp);
            }
            abort(422, 'OTP is required to start the trip.');
        }

        if (in_array($action, ['complete', 'completed'], true)) {
            $result = $this->complete($actor, $id, $otp);
            app(DriverSessionService::class)->finishPendingLogout($actor);

            return $result;
        }

        abort(422, 'Unsupported booking action');
    }

    public function complete(User $actor, string $id, ?string $otp = null): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless(in_array($booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', BookingStatus::OTP_VERIFIED], true), 422, 'Trip has not started.');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        abort_unless((int) $booking->driver_id === (int) $driver->id, 403, 'Driver is not assigned to this booking.');
        $pin = preg_replace('/\D+/', '', (string) $otp) ?? '';
        abort_unless($pin !== '' && hash_equals((string) $booking->end_otp, $pin), 422, 'Invalid completion PIN');

        $started = $booking->trip_started_at ?: $booking->updated_at;
        $duration = max(1, now()->diffInSeconds($started));
        $wait = 0;
        if ($booking->arrived_at && $booking->trip_started_at) {
            $wait = max(0, (int) floor($booking->arrived_at->diffInSeconds($booking->trip_started_at) / 60));
        }
        $trackedKm = (float) ($booking->actual_distance_km ?: 0);
        $this->applyRouteFare($booking, $trackedKm, 0, $wait);
        $booking->refresh();
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $quote = is_array($snapshot['live'] ?? null) ? $snapshot['live'] : $snapshot;
        $totalPaise = (int) ($quote['totalPaise'] ?? $booking->quote_paise);
        $actualKm = (float) ($booking->actual_distance_km ?: $trackedKm);
        $commissionPercent = (float) (DB::table('commission_rules')->where('active', 1)->orderBy('id')->value('percent') ?? 10);
        $commission = (int) round($totalPaise * ($commissionPercent / 100));
        $fleetShare = 0;
        $fleetOwnerId = null;
        $vehicleRow = $booking->vehicle_id
            ? DB::table('vehicles')->where('id', $booking->vehicle_id)->first()
            : ($driver->id ? DB::table('vehicles')->where('driver_id', $driver->id)->orderByDesc('id')->first() : null);
        if ($vehicleRow && ! empty($vehicleRow->fleet_owner_id)) {
            $fleetOwnerId = (int) $vehicleRow->fleet_owner_id;
            $fleetShare = (int) round($totalPaise * 0.05);
        }
        $earning = max(0, $totalPaise - $commission - $fleetShare);

        $booking->update($this->filterColumns([
            'status' => BookingStatus::COMPLETED,
            'trip_ended_at' => now(),
            'actual_distance_km' => $actualKm,
            'duration_seconds' => $duration,
            'wait_minutes' => $wait,
            'final_fare_paise' => $totalPaise,
            'commission_paise' => $commission,
            'driver_earning_paise' => $earning,
            'quote_snapshot' => array_merge($snapshot, [
                'final' => $quote,
                'commissionPercent' => $commissionPercent,
                'commissionPaise' => $commission,
                'driverEarningPaise' => $earning,
                'fleetCommissionPaise' => $fleetShare,
                'durationSeconds' => $duration,
                'waitMinutes' => $wait,
            ]),
        ]));
        Driver::query()->where('id', $driver->id)->update(['duty_status' => 'online']);
        $this->creditDriverWallet((int) $driver->user_id, (int) $booking->id, $earning, $commission, $totalPaise);
        if ($fleetOwnerId && $fleetShare > 0) {
            $this->creditFleetWallet($fleetOwnerId, (int) $booking->id, $fleetShare, $totalPaise);
        }
        $this->debitCustomerWallet((int) $booking->customer_id, (int) $booking->id, $totalPaise, (string) ($booking->payment_mode ?? 'CASH'));
        $fresh = $booking->fresh();
        $invoiceRef = $this->writeInvoice($fresh, $totalPaise);
        $presented = $this->present($fresh, $actor);
        $customerId = (int) $booking->customer_id;
        $driverUserId = (int) $driver->user_id;
        $bookingId = (string) $booking->id;
        app()->terminating(function () use ($fresh, $totalPaise, $invoiceRef, $earning, $customerId, $driverUserId, $bookingId) {
            try {
                $this->emailInvoice($fresh, $totalPaise, $invoiceRef);
                $this->push->notifyUsers(
                    [$customerId],
                    'Trip completed',
                    'Please rate your ride.',
                    ['type' => 'trip', 'event' => 'completed', 'bookingId' => $bookingId],
                );
                $this->push->notifyUsers(
                    [$driverUserId],
                    'Trip completed',
                    'Earning ₹'.number_format($earning / 100, 0).' after commission.',
                    ['type' => 'wallet', 'event' => 'trip_completed', 'bookingId' => $bookingId],
                );
            } catch (\Throwable $e) {
                Log::warning('booking.complete_notify_failed', ['bookingId' => $bookingId, 'error' => $e->getMessage()]);
            }
        });
        Log::info('booking.completed', ['bookingId' => $booking->id, 'farePaise' => $totalPaise, 'earningPaise' => $earning]);
        app(DriverSessionService::class)->finishPendingLogout($actor);

        return $presented;
    }

    public function expireSearching(?int $exceptId = null): int
    {
        $timeout = $this->settings->requestTimeoutSeconds();
        $q = Booking::query()->whereIn('status', BookingStatus::searching());
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }
        if (! Schema::hasColumn('bookings', 'search_expires_at')) {
            $q->where('created_at', '<', now()->subSeconds($timeout));
        } else {
            $q->where(function ($inner) use ($timeout) {
                $inner->where(function ($expired) {
                    $expired->whereNotNull('search_expires_at')->where('search_expires_at', '<=', now());
                })->orWhere(function ($scheduled) use ($timeout) {
                    if (Schema::hasColumn('bookings', 'scheduled_at')) {
                        $scheduled->whereNotNull('scheduled_at')->where('scheduled_at', '<=', now()->subSeconds($timeout));
                    } else {
                        $scheduled->whereRaw('0 = 1');
                    }
                });
            });
        }
        if (Schema::hasColumn('bookings', 'scheduled_at')) {
            $q->where(function ($inner) {
                $inner->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now())
                    ->orWhere(function ($searchWindow) {
                        $searchWindow->whereNotNull('search_expires_at')->where('search_expires_at', '<=', now());
                    });
            });
        }
        $ids = $q->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        Booking::query()->whereIn('id', $ids)->update(['status' => BookingStatus::EXPIRED]);
        foreach ($ids as $id) {
            $this->closeAllOffers((int) $id, 'EXPIRED');
        }

        return $ids->count();
    }

    public function present(Booking $row, ?User $actor = null): array
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $total = (int) ($row->final_fare_paise ?? $snapshot['totalPaise'] ?? $row->quote_paise);
        $driver = $row->driver_id ? Driver::query()->with('user', 'vehicles')->find($row->driver_id) : null;
        $driverUser = $driver?->user;
        $vehicle = $driver?->vehicles?->first();
        $radius = $this->settings->radiusKm();
        $nearbyCount = 0;
        if (BookingStatus::isSearching((string) $row->status) && Schema::hasTable('booking_driver_requests')) {
            $nearbyCount = (int) DB::table('booking_driver_requests')->where('booking_id', $row->id)->whereIn('status', ['OFFERED', 'ACCEPTED'])->count();
        }
        $isCustomer = $actor && in_array($actor->role, ['CUSTOMER', 'CORPORATE', 'ADMIN', 'SUPER_ADMIN'], true);
        $expires = Schema::hasColumn('bookings', 'search_expires_at') ? $row->search_expires_at : null;
        $secondsLeft = $expires ? max(0, $expires->getTimestamp() - time()) : null;

        $driverPayload = $driver ? [
            'id' => (string) $driver->id,
            'name' => $driverUser?->name,
            'phone' => $isCustomer && ! BookingStatus::isSearching((string) $row->status) ? $driverUser?->phone : null,
            'rating' => (float) $driver->rating_avg,
            'photoUrl' => $this->driverPhotoUrl($driver, $driverUser),
            'avatarUrl' => $this->driverPhotoUrl($driver, $driverUser),
            'lat' => $driverUser?->last_lat !== null ? (float) $driverUser->last_lat : null,
            'lng' => $driverUser?->last_lng !== null ? (float) $driverUser->last_lng : null,
            'heading' => $driverUser->last_heading ?? null,
            'vehicleType' => $vehicle?->category ?? $row->category,
            'vehicleNumber' => $vehicle?->registration_no,
            'vehicleBrand' => $vehicle?->brand,
            'vehicleModel' => $vehicle?->model,
        ] : null;

        $pickupKm = null;
        if ($driverUser?->last_lat !== null && $row->pickup_lat !== null) {
            $pickupKm = Geo::haversineKm((float) $driverUser->last_lat, (float) $driverUser->last_lng, (float) $row->pickup_lat, (float) $row->pickup_lng);
        }

        $allowed = $this->allowedActions($row, $actor);

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'customerId' => (string) $row->customer_id,
            'product' => $row->product,
            'category' => $row->category,
            'status' => $row->status,
            'cancelReason' => $row->cancel_reason ?? null,
            'paymentMode' => $row->payment_mode ?? 'CASH',
            'ratings' => $this->ratingsPayload($row),
            'customerReview' => $this->customerReview($row),
            'lifecycle' => BookingStatus::lifecycle((string) $row->status),
            'lifecycleLabel' => str_replace('_', ' ', BookingStatus::lifecycle((string) $row->status)),
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
            'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
            'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
            'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
            'distanceKm' => $row->distance_km !== null ? (float) $row->distance_km : null,
            'actualDistanceKm' => $row->actual_distance_km ?? null,
            'durationSeconds' => $row->duration_seconds ?? null,
            'waitMinutes' => $row->wait_minutes ?? null,
            'quotePaise' => (int) $row->quote_paise,
            'driverId' => $row->driver_id ? (string) $row->driver_id : null,
            'scheduledAt' => optional($row->scheduled_at)?->toIso8601String(),
            'searchRadiusKm' => $radius,
            'nearbyDriverCount' => $nearbyCount,
            'expiresAt' => optional($expires)?->toIso8601String(),
            'searchExpiresAt' => optional($expires)?->toIso8601String(),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'secondsRemaining' => $secondsLeft,
            'startOtp' => $isCustomer ? $row->start_otp : null,
            'endOtp' => $isCustomer && in_array($row->status, [BookingStatus::TRIP_STARTED, BookingStatus::COMPLETED], true) ? $row->end_otp : null,
            'driverName' => $driverPayload['name'] ?? null,
            'driverLat' => $driverPayload['lat'] ?? null,
            'driverLng' => $driverPayload['lng'] ?? null,
            'driverHeading' => $driverPayload['heading'] ?? null,
            'driverPhone' => $driverPayload['phone'] ?? null,
            'pickupDistanceKm' => $pickupKm,
            'driver' => $driverPayload,
            'vehicle' => $vehicle ? [
                'registrationNo' => $vehicle->registration_no,
                'category' => $vehicle->category,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
            ] : null,
            'customer' => $this->customerPayload($row, $actor),
            'allowedActions' => $allowed,
            'fare' => [
                'source' => 'server',
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'gstPaise' => $snapshot['breakdown']['gstPaise'] ?? ($snapshot['final']['breakdown']['gstPaise'] ?? null),
                'discountPaise' => $snapshot['breakdown']['discountPaise'] ?? null,
                'commissionPaise' => $row->commission_paise ?? $snapshot['commissionPaise'] ?? null,
                'driverEarningPaise' => $row->driver_earning_paise ?? $snapshot['driverEarningPaise'] ?? null,
                'driverEarningRupees' => isset($row->driver_earning_paise) ? $row->driver_earning_paise / 100 : null,
                'currency' => 'INR',
                'breakdown' => $snapshot['final']['breakdown'] ?? $snapshot['breakdown'] ?? null,
            ],
            'estimatedFareRupees' => $total / 100,
            'estimatedEarningsRupees' => isset($row->driver_earning_paise) ? $row->driver_earning_paise / 100 : round(($total * 0.9) / 100, 2),
            'kind' => 'ride',
            'navigation' => $this->navigationPayload($row),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationPayload(Booking $row): array
    {
        $pickup = $this->mapsUrl($row->pickup_lat, $row->pickup_lng);
        $drop = $this->mapsUrl($row->drop_lat, $row->drop_lng);
        $started = in_array((string) $row->status, [BookingStatus::TRIP_STARTED, BookingStatus::OTP_VERIFIED, BookingStatus::LEGACY_STARTED], true);

        return [
            'pickupUrl' => $pickup,
            'dropUrl' => $drop,
            'currentUrl' => $started ? $drop : $pickup,
        ];
    }

    private function mapsUrl(mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?api=1&destination='.(float) $lat.','.(float) $lng.'&travelmode=driving';
    }

    /**
     * @return list<string>
     */
    private function allowedActions(Booking $row, ?User $actor): array
    {
        $status = (string) $row->status;
        if (! $actor) {
            return [];
        }
        if ($actor->role === 'CUSTOMER' && (int) $row->customer_id === (int) $actor->id && ! BookingStatus::isTerminal($status) && $status !== BookingStatus::COMPLETED) {
            if (in_array($status, [
                BookingStatus::SEARCHING,
                BookingStatus::LEGACY_REQUESTED,
                BookingStatus::PENDING,
                BookingStatus::DRIVER_ACCEPTED,
                BookingStatus::LEGACY_ASSIGNED,
                BookingStatus::DRIVER_ARRIVED,
                'DRIVER_SEARCHING',
                'CONFIRMED',
            ], true)) {
                return ['cancel'];
            }
        }
        if ($actor->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $actor->id)->first();
            if (! $driver) {
                return [];
            }
            if (BookingStatus::isSearching($status) && (int) $row->driver_id === 0) {
                return ['accept', 'reject'];
            }
            if ((int) $row->driver_id !== (int) $driver->id) {
                return [];
            }
            return match ($status) {
                BookingStatus::DRIVER_ACCEPTED, BookingStatus::LEGACY_ASSIGNED => ['arrive', 'cancel'],
                BookingStatus::DRIVER_ARRIVED => ['verify_otp'],
                BookingStatus::OTP_VERIFIED, BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED => ['complete'],
                default => [],
            };
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerPayload(Booking $row, ?User $actor): ?array
    {
        $customer = User::query()->find($row->customer_id);
        if (! $customer) {
            return null;
        }
        $mask = $actor?->role !== 'DRIVER' || BookingStatus::isSearching((string) $row->status);

        return [
            'name' => $customer->name,
            'phone' => $mask ? null : $customer->phone,
            'phoneMasked' => $customer->phone ? substr($customer->phone, 0, 2).'******'.substr($customer->phone, -2) : null,
            'photoUrl' => app(KycDocumentService::class)->previewUrl($customer->avatar_path ?? null),
            'avatarUrl' => app(KycDocumentService::class)->previewUrl($customer->avatar_path ?? null),
            'lat' => $customer->last_lat !== null ? (float) $customer->last_lat : (float) $row->pickup_lat,
            'lng' => $customer->last_lng !== null ? (float) $customer->last_lng : (float) $row->pickup_lng,
        ];
    }

    private function writeOffers(Booking $booking, array $matches): void
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return;
        }
        $now = now();
        foreach ($matches as $match) {
            $existing = DB::table('booking_driver_requests')
                ->where('booking_id', $booking->id)
                ->where('driver_id', $match['driverId'])
                ->first();
            if ($existing && in_array((string) $existing->status, ['REJECTED', 'ACCEPTED', 'EXPIRED', 'SNOOZED'], true)) {
                continue;
            }
            DB::table('booking_driver_requests')->updateOrInsert(
                ['booking_id' => $booking->id, 'driver_id' => $match['driverId']],
                [
                    'user_id' => $match['userId'],
                    'status' => 'OFFERED',
                    'distance_km' => $match['distanceKm'],
                    'offered_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    private function markRequest(int $bookingId, int $driverId, string $status): void
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return;
        }
        $now = now();
        DB::table('booking_driver_requests')->updateOrInsert(
            ['booking_id' => $bookingId, 'driver_id' => $driverId],
            [
                'status' => $status,
                'responded_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    private function closeOtherOffers(int $bookingId, int $winnerDriverId): void
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return;
        }
        DB::table('booking_driver_requests')
            ->where('booking_id', $bookingId)
            ->where('driver_id', '!=', $winnerDriverId)
            ->where('status', 'OFFERED')
            ->update(['status' => 'EXPIRED', 'updated_at' => now()]);
    }

    private function closeAllOffers(int $bookingId, string $status): void
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return;
        }
        DB::table('booking_driver_requests')
            ->where('booking_id', $bookingId)
            ->where('status', 'OFFERED')
            ->update(['status' => $status, 'updated_at' => now()]);
    }

    /**
     * @return list<int>
     */
    private function offeredBookingIds(int $driverId): array
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return [];
        }

        $this->ensureOfferColumns();

        return DB::table('booking_driver_requests')
            ->where('driver_id', $driverId)
            ->where(function ($query) {
                $query->where('status', 'OFFERED')
                    ->orWhere(function ($snoozed) {
                        $snoozed->where('status', 'SNOOZED')
                            ->whereNotNull('snooze_until')
                            ->where('snooze_until', '<=', now());
                    });
            })
            ->pluck('booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function ensureOfferColumns(): void
    {
        if (! Schema::hasTable('booking_driver_requests')) {
            return;
        }
        if (Schema::hasColumn('booking_driver_requests', 'reject_count') && Schema::hasColumn('booking_driver_requests', 'snooze_until')) {
            return;
        }
        Schema::table('booking_driver_requests', function ($table) {
            if (! Schema::hasColumn('booking_driver_requests', 'reject_count')) {
                $table->unsignedTinyInteger('reject_count')->default(0);
            }
            if (! Schema::hasColumn('booking_driver_requests', 'snooze_until')) {
                $table->timestamp('snooze_until')->nullable();
            }
        });
    }

    /**
     * A customer can be waiting while every driver is offline. When one comes
     * online, attach that waiting search immediately.
     */
    public function offerWaitingToDriver(User $actor, ?float $lat = null, ?float $lng = null): void
    {
        if (! Cache::add('offer-wait:'.$actor->id, 1, now()->addSeconds(12))) {
            return;
        }
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->first();
        if (! $driver || ! $driver->online) {
            return;
        }
        $duty = strtolower((string) $driver->duty_status);
        if (! in_array($duty, ['online', 'available'], true)) {
            return;
        }
        $lat = $lat ?? ($actor->last_lat !== null ? (float) $actor->last_lat : null);
        $lng = $lng ?? ($actor->last_lng !== null ? (float) $actor->last_lng : null);
        if ($lat === null || $lng === null) {
            return;
        }
        $category = strtoupper((string) ($driver->vehicles->first()?->category ?? ''));
        if ($category === '') {
            return;
        }
        $radius = $this->settings->radiusKm();
        $rows = Booking::query()
            ->where('status', BookingStatus::SEARCHING)
            ->whereNull('driver_id')
            ->whereIn('product', ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'SCHEDULE'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $pushes = [];
        foreach ($rows as $booking) {
            if (strcasecmp((string) $booking->category, $category) !== 0) {
                continue;
            }
            $km = Geo::haversineKm($lat, $lng, (float) $booking->pickup_lat, (float) $booking->pickup_lng);
            if ($km > $radius) {
                continue;
            }
            $this->writeOffers($booking, [[
                'driverId' => (int) $driver->id,
                'userId' => (int) $actor->id,
                'distanceKm' => round($km, 2),
            ]]);
            $pushes[] = [
                'title' => 'New KarnaRide booking',
                'body' => trim(($booking->pickup_text ?: 'Pickup').' → '.($booking->drop_text ?: 'Drop')),
                'bookingId' => (string) $booking->id,
            ];
        }
        if ($pushes === []) {
            return;
        }
        $userId = (int) $actor->id;
        app()->terminating(function () use ($pushes, $userId) {
            foreach ($pushes as $push) {
                $this->push->notifyUsers([$userId], $push['title'], $push['body'], [
                    'type' => 'booking_offer',
                    'event' => 'new_booking',
                    'bookingId' => $push['bookingId'],
                ]);
            }
        });
    }

    public function offersForDriver(User $actor): array
    {
        $this->expireSearching();
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (! $driver) {
            return [];
        }
        $ids = $this->offeredBookingIds((int) $driver->id);
        if (! $ids) {
            return [];
        }

        return Booking::query()
            ->whereIn('id', $ids)
            ->where('status', BookingStatus::SEARCHING)
            ->whereNull('driver_id')
            ->whereIn('product', ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'SCHEDULE'])
            ->orderByDesc('id')
            ->get()
            ->map(function (Booking $row) use ($actor, $driver) {
                $presented = $this->present($row, $actor);
                $req = Schema::hasTable('booking_driver_requests')
                    ? DB::table('booking_driver_requests')->where('booking_id', $row->id)->where('driver_id', $driver->id)->first()
                    : null;
                $presented['pickupDistanceKm'] = $req->distance_km ?? $presented['pickupDistanceKm'];
                $presented['actions'] = ['accept', 'reject'];
                $presented['kind'] = 'ride';

                return $presented;
            })
            ->all();
    }

    private function assignedToDriver(User $actor, string $id): Booking
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only the assigned driver can update this trip');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $booking = Booking::query()->findOrFail($id);
        abort_unless((int) $booking->driver_id === (int) $driver->id, 403, 'Driver is not assigned to this booking.');

        return $booking;
    }

    private function findVisible(User $actor, string $id): Booking
    {
        $booking = Booking::query()->findOrFail($id);
        if (in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $booking;
        }
        if ((string) $booking->customer_id === (string) $actor->id) {
            return $booking;
        }
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if ($driver && (string) $booking->driver_id === (string) $driver->id) {
            return $booking;
        }
        if ($driver && in_array((int) $booking->id, $this->offeredBookingIds((int) $driver->id), true)) {
            return $booking;
        }
        abort(403, 'Forbidden');
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     */
    private function storeStops(Booking $booking, array $stops): void
    {
        if ($stops === [] || ! Schema::hasTable('booking_stops')) {
            return;
        }
        foreach (array_values($stops) as $index => $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $label = (string) ($stop['label'] ?? $stop['title'] ?? $stop['address'] ?? ('Stop '.($index + 1)));
            $lat = isset($stop['lat']) ? (float) $stop['lat'] : null;
            $lng = isset($stop['lng']) ? (float) $stop['lng'] : null;
            if ($lat === null || $lng === null) {
                continue;
            }
            $row = array_filter(
                [
                    'booking_id' => $booking->id,
                    'label' => $label,
                    'title' => $label,
                    'address' => (string) ($stop['address'] ?? $label),
                    'lat' => $lat,
                    'lng' => $lng,
                    'sort_order' => $index,
                    'seq' => $index,
                    'position' => $index,
                    'created_at' => now(),
                ],
                fn ($key) => Schema::hasColumn('booking_stops', $key),
                ARRAY_FILTER_USE_KEY,
            );
            if ($row !== []) {
                DB::table('booking_stops')->insert($row);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filterColumns(array $row): array
    {
        if (! Schema::hasColumn('bookings', 'cancel_reason')) {
            try {
                Schema::table('bookings', fn ($table) => $table->string('cancel_reason', 500)->nullable());
            } catch (\Throwable) {
            }
        }
        if (! Schema::hasColumn('bookings', 'payment_mode')) {
            try {
                Schema::table('bookings', fn ($table) => $table->string('payment_mode', 24)->nullable());
            } catch (\Throwable) {
            }
        }

        return array_filter(
            $row,
            fn ($key) => Schema::hasColumn('bookings', $key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function normalizePaymentMode(mixed $value): string
    {
        $raw = strtoupper(trim((string) $value));
        if (str_contains($raw, 'WALLET') || $raw === 'KARNACAB CASH') {
            return 'WALLET';
        }
        if (str_contains($raw, 'UPI')) {
            return 'UPI';
        }

        return $raw !== '' ? $raw : 'CASH';
    }

    private function debitCustomerWallet(int $userId, int $bookingId, int $amountPaise, string $paymentMode): void
    {
        $mode = strtoupper($paymentMode);
        if (! in_array($mode, ['WALLET', 'KARNACAB_CASH', 'CASH_WALLET'], true)) {
            return;
        }
        app(CustomerWallet::class)->debit($userId, $amountPaise, 'Trip fare', $bookingId, 'trip');
    }

    private function driverPhotoUrl(?Driver $driver, ?User $driverUser): ?string
    {
        $docs = app(KycDocumentService::class);
        $avatar = $docs->previewUrl($driverUser?->avatar_path ?? null);
        if ($avatar) {
            return $avatar;
        }
        if (! $driver || ! Schema::hasTable('driver_documents')) {
            return null;
        }
        $preferred = ['LIVE_PHOTO', 'VEHICLE_DRIVER_PHOTO', 'PROFILE_PHOTO', 'VEHICLE_PHOTO'];
        $rows = DriverDocument::query()->where('driver_id', $driver->id)->get();
        foreach ($preferred as $type) {
            $match = $rows->first(fn ($row) => strtoupper((string) $row->type) === $type && ! empty($row->storage_key));
            if ($match) {
                return $docs->previewUrl($match->storage_key);
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ratingsPayload(Booking $row): array
    {
        if (! Schema::hasTable('booking_ratings')) {
            return [];
        }

        return DB::table('booking_ratings')->where('booking_id', $row->id)->orderBy('id')->get()->map(fn ($rating) => [
            'fromRole' => $rating->from_role ?? 'CUSTOMER',
            'stars' => (int) ($rating->stars ?? 0),
            'comment' => $rating->comment ?? null,
        ])->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerReview(Booking $row): ?array
    {
        foreach ($this->ratingsPayload($row) as $rating) {
            if (($rating['fromRole'] ?? '') === 'CUSTOMER') {
                return $rating;
            }
        }

        return null;
    }

    public function trackTripProgress(User $actor, string $bookingId, float $lat, float $lng): void
    {
        if (! Schema::hasColumn('bookings', 'actual_distance_km')) {
            return;
        }
        try {
            $booking = $this->findVisible($actor, $bookingId);
        } catch (\Throwable) {
            return;
        }
        if (! in_array($booking->status, BookingStatus::inTrip(), true)) {
            return;
        }
        $this->ensureTripKmLogs();
        $prev = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderByDesc('id')->first();
        $prevLat = $prev ? (float) $prev->lat : ($actor->last_lat !== null ? (float) $actor->last_lat : (float) $booking->pickup_lat);
        $prevLng = $prev ? (float) $prev->lng : ($actor->last_lng !== null ? (float) $actor->last_lng : (float) $booking->pickup_lng);
        $delta = Geo::haversineKm($prevLat, $prevLng, $lat, $lng);
        $tracked = (float) ($booking->actual_distance_km ?? 0);
        $counted = 0.0;
        if ($delta > 0.03 && $delta <= 8) {
            $counted = $delta;
            $tracked += $delta;
        }
        DB::table('trip_km_logs')->insert([
            'booking_id' => $booking->id,
            'lat' => $lat,
            'lng' => $lng,
            'delta_km' => round($counted, 3),
            'tracked_km' => round(max(0, $tracked), 3),
            'recorded_at' => now(),
        ]);
        $remaining = $this->remainingRouteKm($booking, $lat, $lng);
        $this->applyRouteFare($booking, $tracked, $remaining);
    }

    private function ensureTripKmLogs(): void
    {
        if (Schema::hasTable('trip_km_logs')) {
            return;
        }
        Schema::create('trip_km_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('delta_km', 8, 3)->default(0);
            $table->decimal('tracked_km', 8, 3)->default(0);
            $table->timestamp('recorded_at')->nullable();
        });
    }

    /**
     * Reprice every service from the current route. A 20 km booking that
     * becomes a 24 km route is billed at 24 km. The fare never drops below
     * the distance the customer booked.
     */
    private function applyRouteFare(Booking $booking, float $drivenKm, float $remainingKm, ?int $waitMinutes = null): void
    {
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $inputs = is_array($snapshot['fareInputs'] ?? null) ? $snapshot['fareInputs'] : [];
        $bookedKm = (float) ($snapshot['bookedDistanceKm'] ?? 0);
        if ($bookedKm <= 0) {
            $bookedKm = (float) ($booking->distance_km ?: 0);
        }
        $product = strtoupper((string) $booking->product);
        $projected = $drivenKm + max(0, $remainingKm);
        $roundTrip = (bool) ($inputs['roundTrip'] ?? $product === 'ROUND_WAY');
        if ($product === 'ROUND_WAY' && $drivenKm > max(1, $bookedKm * 2)) {
            $fareKm = $drivenKm;
            $roundTrip = false;
        } else {
            $fareKm = max($bookedKm, $projected);
        }
        $lastBilled = (float) ($snapshot['liveBilledKm'] ?? $snapshot['billedKm'] ?? $bookedKm);
        $patch = ['actual_distance_km' => round(max(0, $drivenKm), 3)];
        if (abs($fareKm - $lastBilled) >= 0.3) {
            $wait = $waitMinutes ?? (int) ($inputs['waitMinutes'] ?? $booking->wait_minutes ?? 0);
            $quote = $this->fares->quote([
                'lockProduct' => true,
                'product' => $product,
                'category' => $booking->category,
                'distanceKm' => $fareKm,
                'districtId' => $booking->district_id,
                'hours' => $inputs['hours'] ?? null,
                'extraHours' => $inputs['extraHours'] ?? 0,
                'stopCount' => $inputs['stopCount'] ?? 0,
                'roundTrip' => $roundTrip,
                'nightStayNights' => $inputs['nightStayNights'] ?? 0,
                'waitMinutes' => $wait,
                'night' => $inputs['night'] ?? false,
                'tollPaise' => $inputs['tollPaise'] ?? 0,
                'parkingPaise' => $inputs['parkingPaise'] ?? 0,
                'pickupLat' => $booking->pickup_lat,
                'pickupLng' => $booking->pickup_lng,
            ]);
            $discount = (int) ($snapshot['coupon']['discountPaise'] ?? 0);
            if ($discount > 0) {
                $quote['totalPaise'] = max(0, (int) $quote['totalPaise'] - $discount);
                $quote['totalRupees'] = $quote['totalPaise'] / 100;
            }
            $snapshot = array_merge($snapshot, $quote);
            $snapshot['fareInputs'] = $inputs;
            $snapshot['bookedDistanceKm'] = $bookedKm;
            $snapshot['liveBilledKm'] = (float) ($quote['billedKm'] ?? $fareKm);
            $snapshot['live'] = $quote;
            $patch['distance_km'] = $quote['billedKm'] ?? $fareKm;
            $patch['quote_paise'] = (int) $quote['totalPaise'];
            $patch['quote_snapshot'] = $snapshot;
        } elseif ($remainingKm > 0 || isset($snapshot['remainingKm'])) {
            $snapshot['remainingKm'] = round($remainingKm, 2);
            $patch['quote_snapshot'] = $snapshot;
        }
        $booking->update($this->filterColumns($patch));
    }

    private function remainingRouteKm(Booking $booking, float $lat, float $lng): float
    {
        if ($booking->drop_lat === null || $booking->drop_lng === null) {
            return 0;
        }
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $checkedAt = (int) ($snapshot['routeCheckedAt'] ?? 0);
        if ((time() - $checkedAt) < 20 && isset($snapshot['remainingKm'])) {
            return (float) $snapshot['remainingKm'];
        }
        $road = $this->fares->roadDistanceKm($lat, $lng, (float) $booking->drop_lat, (float) $booking->drop_lng);
        $remaining = $road ?? Geo::haversineKm($lat, $lng, (float) $booking->drop_lat, (float) $booking->drop_lng);
        $snapshot['routeCheckedAt'] = time();
        $snapshot['remainingKm'] = round($remaining, 2);
        $booking->quote_snapshot = $snapshot;

        return $remaining;
    }

    private function notifyOps(Booking $booking, string $title): void
    {
        $email = (string) (DB::table('system_settings')->where('key', 'booking_notify_email')->value('value')
            ?? config('karnacab.booking_notify_email', 'karnacabofficial@gmail.com'));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $body = 'Booking '.$booking->public_ref.' · '.($booking->product ?? '').' · '.($booking->status ?? '')."\n"
            .($booking->pickup_text ?? 'Pickup').' → '.($booking->drop_text ?? 'Drop');
        try {
            Mail::to($email)->send(new EventNoticeMail($title, $body));
        } catch (\Throwable $e) {
            Log::warning('booking.ops_mail_failed', ['bookingId' => $booking->id, 'error' => $e->getMessage()]);
        }
        $adminIds = User::query()->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($adminIds !== []) {
            $this->push->notifyUsers(
                $adminIds,
                $title,
                $body,
                ['type' => 'ops_booking', 'event' => 'new_booking', 'bookingId' => (string) $booking->id],
            );
        }
    }

    private function emailCustomer(int $userId, string $title, string $body): void
    {
        $user = User::query()->find($userId);
        $email = (string) ($user->email ?? '');
        if ($email === '' || str_ends_with($email, '@otp.karnacab.local')) {
            return;
        }
        try {
            Mail::to($email)->send(new EventNoticeMail($title, $body));
        } catch (\Throwable $e) {
            Log::warning('booking.notice_mail_failed', ['userId' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function emailInvoice(Booking $booking, int $totalPaise, ?string $invoiceRef): void
    {
        if (! $invoiceRef) {
            return;
        }
        $user = User::query()->find($booking->customer_id);
        $email = (string) ($user->email ?? '');
        if (! $user || $email === '' || str_ends_with($email, '@otp.karnacab.local')) {
            return;
        }
        try {
            Mail::to($email)->send(new TripInvoiceMail($user, $booking, $totalPaise, $invoiceRef));
        } catch (\Throwable $e) {
            Log::warning('booking.invoice_mail_failed', ['bookingId' => $booking->id, 'error' => $e->getMessage()]);
        }
    }

    private function writeInvoice(Booking $booking, int $totalPaise): ?string
    {
        if (! Schema::hasTable('invoices')) {
            return null;
        }
        $existing = DB::table('invoices')->where('booking_id', $booking->id)->first();
        if ($existing) {
            return (string) ($existing->public_ref ?? ('INV'.$booking->id));
        }
        $ref = 'INV'.strtoupper(Str::random(8));
        $row = [
            'public_ref' => $ref,
            'customer_id' => $booking->customer_id,
            'booking_id' => $booking->id,
            'kind' => 'ride',
            'status' => 'paid',
            'total_paise' => $totalPaise,
            'currency' => 'INR',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('invoices')->insert(array_filter(
            $row,
            fn ($key) => Schema::hasColumn('invoices', $key),
            ARRAY_FILTER_USE_KEY,
        ));

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filterLedger(array $row): array
    {
        return array_filter(
            $row,
            fn ($key) => Schema::hasColumn('wallet_ledger', $key),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function creditDriverWallet(int $userId, int $bookingId, int $earningPaise, int $commissionPaise, int $grossPaise): void
    {
        if ($earningPaise <= 0 || ! Schema::hasTable('wallets')) {
            return;
        }
        $wallet = DB::table('wallets')->where('owner_user_id', $userId)->where('owner_type', 'DRIVER')->first();
        if (! $wallet) {
            $id = DB::table('wallets')->insertGetId($this->filterWallet([
                'owner_user_id' => $userId,
                'owner_type' => 'DRIVER',
                'balance_paise' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $wallet = DB::table('wallets')->where('id', $id)->first();
        }
        $before = (int) $wallet->balance_paise;
        $after = $before + $earningPaise;
        DB::table('wallets')->where('id', $wallet->id)->update($this->filterWallet(['balance_paise' => $after, 'updated_at' => now()]));
        if (Schema::hasTable('wallet_ledger')) {
            $exists = DB::table('wallet_ledger')->where('booking_id', $bookingId)->where('wallet_id', $wallet->id)->where('kind', 'trip')->exists();
            if (! $exists) {
                DB::table('wallet_ledger')->insert($this->filterLedger([
                    'public_ref' => 'WL'.strtoupper(Str::random(10)),
                    'wallet_id' => $wallet->id,
                    'booking_id' => $bookingId,
                    'owner_user_id' => $userId,
                    'account' => 'DRIVER',
                    'direction' => 'credit',
                    'amount_paise' => $earningPaise,
                    'commission_paise' => $commissionPaise,
                    'gross_paise' => $grossPaise,
                    'balance_before_paise' => $before,
                    'balance_after_paise' => $after,
                    'kind' => 'trip',
                    'status' => 'posted',
                    'note' => 'Trip earning',
                    'created_at' => now(),
                ]));
            }
        }
    }

    private function creditFleetWallet(int $fleetOwnerId, int $bookingId, int $amountPaise, int $grossPaise): void
    {
        if ($amountPaise <= 0 || ! Schema::hasTable('wallets') || ! Schema::hasTable('fleet_owners')) {
            return;
        }
        $userId = (int) DB::table('fleet_owners')->where('id', $fleetOwnerId)->value('user_id');
        if ($userId < 1) {
            return;
        }
        $q = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $q->whereIn('owner_type', ['FLEET', 'FLEET_OWNER']);
        }
        $wallet = $q->orderBy('id')->first();
        if (! $wallet) {
            $id = DB::table('wallets')->insertGetId($this->filterWallet([
                'owner_user_id' => $userId,
                'owner_type' => 'FLEET',
                'balance_paise' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $wallet = DB::table('wallets')->where('id', $id)->first();
        }
        if (! $wallet) {
            return;
        }
        $before = (int) $wallet->balance_paise;
        $after = $before + $amountPaise;
        DB::table('wallets')->where('id', $wallet->id)->update($this->filterWallet(['balance_paise' => $after, 'updated_at' => now()]));
        if (Schema::hasTable('wallet_ledger')) {
            $exists = DB::table('wallet_ledger')->where('booking_id', $bookingId)->where('wallet_id', $wallet->id)->where('kind', 'fleet_commission')->exists();
            if (! $exists) {
                DB::table('wallet_ledger')->insert($this->filterLedger([
                    'public_ref' => 'FL'.strtoupper(Str::random(10)),
                    'wallet_id' => $wallet->id,
                    'booking_id' => $bookingId,
                    'owner_user_id' => $userId,
                    'account' => 'FLEET',
                    'direction' => 'credit',
                    'amount_paise' => $amountPaise,
                    'commission_paise' => $amountPaise,
                    'gross_paise' => $grossPaise,
                    'balance_before_paise' => $before,
                    'balance_after_paise' => $after,
                    'kind' => 'fleet_commission',
                    'status' => 'posted',
                    'note' => 'Fleet owner commission 5%',
                    'created_at' => now(),
                ]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filterWallet(array $row): array
    {
        return array_filter(
            $row,
            fn ($key) => Schema::hasColumn('wallets', $key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
