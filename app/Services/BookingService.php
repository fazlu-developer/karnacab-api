<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use App\Support\BookingStatus;
use App\Support\Geo;
use App\Support\ServiceArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $timeout = $this->settings->requestTimeoutSeconds();
        $radius = $this->settings->radiusKm();
        $scheduled = ! empty($dto['scheduledAt']);
        $status = $scheduled ? 'CONFIRMED' : BookingStatus::SEARCHING;

        $matches = $scheduled ? [] : $this->nearby->search(
            $pickupLat,
            $pickupLng,
            (string) $dto['category'],
            $radius,
            false,
            isset($dto['districtId']) ? (int) $dto['districtId'] : ($actor->district_id ? (int) $actor->district_id : null),
            (string) ($dto['product'] ?? 'LOCAL_CAB'),
        );
        abort_if(! $scheduled && $matches === [], 422, 'No nearby drivers found.');
        $mode = $this->normalizePaymentMode($dto['paymentMode'] ?? $dto['payment_mode'] ?? 'CASH');
        if ($mode === 'WALLET') {
            $wallet = Schema::hasTable('wallets')
                ? DB::table('wallets')->where('owner_user_id', $actor->id)->orderBy('id')->first()
                : null;
            abort_unless($wallet && (int) $wallet->balance_paise >= (int) $quote['totalPaise'], 422, 'Add wallet balance to pay with wallet.');
        }

        $booking = Booking::query()->create($this->filterColumns([
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'district_id' => $dto['districtId'] ?? $actor->district_id,
            'product' => $dto['product'],
            'category' => $dto['category'],
            'status' => $status,
            'search_expires_at' => $scheduled ? null : now()->addSeconds($timeout),
            'pickup_text' => $dto['pickupText'] ?? $dto['pickup'] ?? 'Pickup',
            'drop_text' => $dto['dropText'] ?? $dto['drop'] ?? 'Drop',
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'drop_lat' => $dropLat,
            'drop_lng' => $dropLng,
            'distance_km' => $quote['billedKm'] ?? $distanceKm,
            'quote_paise' => $quote['totalPaise'],
            'quote_snapshot' => $quote,
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

        if (! $scheduled) {
            $this->writeOffers($booking, $matches);
            $this->push->notifyUsers(
                array_map(fn ($row) => (int) ($row['userId'] ?? 0), $matches),
                'New KarnaCab booking',
                trim(($booking->pickup_text ?: 'Pickup').' → '.($booking->drop_text ?: 'Drop')),
                [
                    'type' => 'booking_offer',
                    'event' => 'new_booking',
                    'bookingId' => (string) $booking->id,
                ],
            );
        }

        Log::info('booking.created', [
            'bookingId' => $booking->id,
            'status' => $booking->status,
            'nearby' => count($matches),
            'radiusKm' => $radius,
        ]);

        return $this->present($booking->fresh(), $actor);
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
        $this->expireSearching();
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->firstOrFail();
        abort_unless((bool) $driver->online, 422, 'Driver is no longer available.');
        abort_unless(in_array(strtolower((string) $driver->duty_status), ['online'], true), 422, 'Driver is no longer available.');
        $busy = Booking::query()->where('driver_id', $driver->id)->whereIn('status', BookingStatus::openForDriver())->exists();
        abort_if($busy, 422, 'Driver is no longer available.');

        $vehicle = $driver->vehicles->first();
        abort_unless($vehicle, 422, 'Add a vehicle before accepting rides.');

        $preview = Booking::query()->find($id);
        abort_unless($preview, 404, 'Booking not found');
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
            if (Schema::hasColumn('bookings', 'search_expires_at')
                && $booking->search_expires_at
                && $booking->search_expires_at->isPast()
                && BookingStatus::isSearching((string) $booking->status)) {
                $booking->update(['status' => BookingStatus::EXPIRED]);
                abort(422, 'Booking has expired.');
            }
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
        $this->push->notifyUsers(
            [(int) $booking->customer_id],
            'Driver assigned',
            ($actor->name ?: 'Your driver').' is on the way.',
            ['type' => 'trip', 'event' => 'accepted', 'bookingId' => (string) $booking->id],
        );

        return $this->present($booking, $actor);
    }

    public function reject(User $actor, string $id): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only drivers can reject bookings');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $booking = Booking::query()->findOrFail($id);
        abort_unless(BookingStatus::isSearching((string) $booking->status), 422, 'This booking is no longer available.');
        $this->markRequest((int) $booking->id, (int) $driver->id, 'REJECTED');

        return $this->present($booking, $actor);
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
            $result = $this->complete($actor, $id);
            app(DriverSessionService::class)->finishPendingLogout($actor);

            return $result;
        }

        abort(422, 'Unsupported booking action');
    }

    public function complete(User $actor, string $id): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless(in_array($booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', BookingStatus::OTP_VERIFIED], true), 422, 'Trip has not started.');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        abort_unless((int) $booking->driver_id === (int) $driver->id, 403, 'Driver is not assigned to this booking.');

        $started = $booking->trip_started_at ?: $booking->updated_at;
        $duration = max(1, now()->diffInSeconds($started));
        $wait = 0;
        if ($booking->arrived_at && $booking->trip_started_at) {
            $wait = max(0, (int) floor($booking->arrived_at->diffInSeconds($booking->trip_started_at) / 60));
        }
        $actualKm = (float) ($booking->distance_km ?: Geo::haversineKm(
            (float) $booking->pickup_lat,
            (float) $booking->pickup_lng,
            (float) $booking->drop_lat,
            (float) $booking->drop_lng,
        ));
        $quote = $this->fares->quote([
            'product' => $booking->product,
            'category' => $booking->category,
            'distanceKm' => $actualKm,
            'districtId' => $booking->district_id,
            'waitMinutes' => $wait,
            'pickupLat' => $booking->pickup_lat,
            'pickupLng' => $booking->pickup_lng,
        ]);
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $totalPaise = (int) ($quote['totalPaise'] ?? $booking->quote_paise);
        $commissionPercent = (float) (DB::table('commission_rules')->where('active', 1)->orderBy('id')->value('percent') ?? 10);
        $commission = (int) round($totalPaise * ($commissionPercent / 100));
        $earning = max(0, $totalPaise - $commission);

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
                'durationSeconds' => $duration,
                'waitMinutes' => $wait,
            ]),
        ]));
        Driver::query()->where('id', $driver->id)->update(['duty_status' => 'online']);
        $this->creditDriverWallet((int) $driver->user_id, (int) $booking->id, $earning, $commission, $totalPaise);
        $this->debitCustomerWallet((int) $booking->customer_id, (int) $booking->id, $totalPaise, (string) ($booking->payment_mode ?? 'CASH'));
        $this->writeInvoice($booking->fresh(), $totalPaise);
        $this->push->notifyUsers(
            [(int) $booking->customer_id],
            'Trip completed',
            'Please rate your ride.',
            ['type' => 'trip', 'event' => 'completed', 'bookingId' => (string) $booking->id],
        );
        $this->push->notifyUsers(
            [(int) $driver->user_id],
            'Trip completed',
            'Earning ₹'.number_format($earning / 100, 0).' after commission.',
            ['type' => 'wallet', 'event' => 'trip_completed', 'bookingId' => (string) $booking->id],
        );
        Log::info('booking.completed', ['bookingId' => $booking->id, 'farePaise' => $totalPaise, 'earningPaise' => $earning]);
        app(DriverSessionService::class)->finishPendingLogout($actor);

        return $this->present($booking->fresh(), $actor);
    }

    public function expireSearching(): int
    {
        if (! Schema::hasColumn('bookings', 'search_expires_at')) {
            $q = Booking::query()->whereIn('status', BookingStatus::searching())->where('created_at', '<', now()->subSeconds($this->settings->requestTimeoutSeconds()));
        } else {
            $q = Booking::query()->whereIn('status', BookingStatus::searching())->where(function ($inner) {
                $inner->whereNotNull('search_expires_at')->where('search_expires_at', '<=', now());
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
            'photoUrl' => app(KycDocumentService::class)->previewUrl($driverUser?->avatar_path ?? null),
            'avatarUrl' => app(KycDocumentService::class)->previewUrl($driverUser?->avatar_path ?? null),
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
        DB::table('booking_driver_requests')->where('booking_id', $bookingId)->where('driver_id', $driverId)->update([
            'status' => $status,
            'responded_at' => now(),
            'updated_at' => now(),
        ]);
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

        return DB::table('booking_driver_requests')
            ->where('driver_id', $driverId)
            ->where('status', 'OFFERED')
            ->pluck('booking_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
            ->whereIn('status', BookingStatus::searching())
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
        if ($amountPaise <= 0 || ! Schema::hasTable('wallets')) {
            return;
        }
        $mode = strtoupper($paymentMode);
        if (! in_array($mode, ['WALLET', 'KARNACAB_CASH', 'CASH_WALLET'], true)) {
            return;
        }
        $wallet = DB::table('wallets')->where('owner_user_id', $userId)->orderBy('id')->first();
        abort_unless($wallet, 422, 'Wallet not found');
        $before = (int) $wallet->balance_paise;
        abort_unless($before >= $amountPaise, 422, 'Insufficient wallet balance');
        if (Schema::hasTable('wallet_ledger')) {
            $exists = DB::table('wallet_ledger')->where('booking_id', $bookingId)->where('wallet_id', $wallet->id)->where('kind', 'trip')->where('direction', 'debit')->exists();
            if ($exists) {
                return;
            }
        }
        $after = $before - $amountPaise;
        DB::table('wallets')->where('id', $wallet->id)->update($this->filterWallet(['balance_paise' => $after, 'updated_at' => now()]));
        if (Schema::hasTable('wallet_ledger')) {
            DB::table('wallet_ledger')->insert($this->filterLedger([
                'public_ref' => 'WD'.strtoupper(Str::random(10)),
                'wallet_id' => $wallet->id,
                'booking_id' => $bookingId,
                'owner_user_id' => $userId,
                'account' => 'CUSTOMER',
                'direction' => 'debit',
                'amount_paise' => $amountPaise,
                'balance_before_paise' => $before,
                'balance_after_paise' => $after,
                'kind' => 'trip',
                'status' => 'posted',
                'note' => 'Trip fare',
                'created_at' => now(),
            ]));
        }
    }

    private function writeInvoice(Booking $booking, int $totalPaise): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }
        $exists = DB::table('invoices')->where('booking_id', $booking->id)->exists();
        if ($exists) {
            return;
        }
        $row = [
            'public_ref' => 'INV'.strtoupper(Str::random(8)),
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
