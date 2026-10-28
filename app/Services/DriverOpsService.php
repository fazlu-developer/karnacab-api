<?php

namespace App\Services;

use App\Mail\EventNoticeMail;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\TerritoryContacts;
use App\Support\TripFareDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class DriverOpsService
{
    public function me(User $actor): array
    {
        return $this->dashboard($actor);
    }

    public function dashboard(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles', 'documents', 'user')->firstOrFail();
        $kycStatus = (string) ($driver->kyc_status ?? 'pending');
        $kyc = strtolower($kycStatus);
        $blocked = in_array($kyc, ['rejected', 'suspended', 'left_fleet'], true);
        $approved = in_array($kyc, ['verified', 'approved', 'active'], true);
        if ($approved) {
            try {
                app(WalletAccountService::class)->ensureSchema();
                $this->grantWelcomeBonus($driver);
                $driver->refresh();
                $offer = app(TerritoryBonusService::class)->forUser($actor, 'joining');
                $offerPaise = ((int) ($offer['amountRupees'] ?? 0)) * 100;
                if ($offerPaise > 0) {
                    app(WalletAccountService::class)->reconcileJoiningBonus((int) $actor->id, 'DRIVER', $offerPaise);
                }
            } catch (\Throwable $e) {
                Log::warning('driver.welcome_bonus_skipped', [
                    'driverId' => $driver->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $fleetUnassigned = (bool) $driver->fleet_owner_id && $driver->vehicles->isEmpty();
        $canGoOnline = $approved && ! $blocked && ! $fleetUnassigned;
        $online = (bool) $driver->online;
        $duty = $driver->duty_status ?: ($online ? 'online' : 'offline');
        $canReceive = $canGoOnline && $online && in_array(strtolower((string) $duty), ['online', 'available'], true);
        $vehicle = $driver->vehicles->first();
        $wallet = Schema::hasTable('wallets')
            ? DB::table('wallets')->where('owner_user_id', $actor->id)->when(
                Schema::hasColumn('wallets', 'owner_type'),
                fn ($q) => $q->where(function ($inner) {
                    $inner->where('owner_type', 'DRIVER')->orWhereNull('owner_type');
                }),
            )->orderByDesc('id')->first()
            : null;
        $balance = (int) ($wallet?->balance_paise ?? 0);
        $todayStart = now()->timezone('Asia/Kolkata')->startOfDay()->utc();
        $todayStats = $this->periodEarnings((int) $driver->id, (int) $actor->id, $todayStart, now());
        $todayRides = (int) $todayStats['rides'];
        $todayEarnPaise = (int) ($todayStats['netPaise'] ?? $todayStats['earningsPaise'] ?? 0);
        $todayParcels = (int) $todayStats['parcels'];
        $pending = 0;
        $offerIds = [];
        if ($canReceive) {
            foreach (app(\App\Services\BookingService::class)->offersForDriver($actor) as $row) {
                $offerIds[(string) ($row['id'] ?? '')] = true;
            }
            if (Schema::hasTable('parcel_shipments') && $driver) {
                $dismissals = app(OfferDismissal::class);
                $parcelCount = DB::table('parcel_shipments')
                    ->whereNull('driver_id')
                    ->when(Schema::hasColumn('parcel_shipments', 'booking_id'), fn ($q) => $q->whereNull('booking_id'))
                    ->whereIn('status', ['created', 'paid', 'CREATED', 'PAID'])
                    ->where('created_at', '>=', now()->subMinutes(20))
                    ->pluck('id')
                    ->filter(fn ($id) => ! $dismissals->hidden((int) $driver->id, 'parcel', (string) $id))
                    ->count();
                $pending = count(array_filter(array_keys($offerIds))) + $parcelCount;
            }
        }
        if (! isset($pending) || $pending === 0) {
            $pending = count(array_filter(array_keys($offerIds)));
        }
        $rating = $this->rideRatingAverage((int) $driver->id);
        $activeRide = \App\Models\Booking::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', \App\Support\BookingStatus::openForDriver())
            ->orderByDesc('id')
            ->first();
        $activeParcel = Schema::hasTable('parcel_shipments')
            ? DB::table('parcel_shipments')->where('driver_id', $driver->id)->whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])->orderByDesc('id')->first()
            : null;
        $kycDocs = app(KycDocumentService::class);
        $documents = $driver->documents->map(fn ($doc) => $kycDocs->present($doc))->all();
        $incentives = [];
        try {
            $incentives = app(IncentiveService::class)->driverProgramCards($actor);
        } catch (\Throwable) {
            $incentives = [];
        }
        $inbox = Schema::hasTable('user_notifications')
            ? DB::table('user_notifications')->where('user_id', $actor->id)->orderByDesc('id')->limit(30)->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->title ?? $row->subject ?? 'Update',
                'body' => $row->body ?? $row->message ?? '',
                'read' => (bool) ($row->read_at ?? $row->is_read ?? false),
                'createdAt' => $row->created_at,
            ])->all()
            : [];

        return [
            'id' => (string) $driver->id,
            'userId' => (string) $driver->user_id,
            'name' => $driver->user?->name,
            'phone' => $driver->user?->phone,
            'email' => str_ends_with((string) ($driver->user?->email ?? ''), '@otp.karnacab.local')
                ? ''
                : $driver->user?->email,
            'gender' => $driver->user?->gender,
            'avatarUrl' => app(KycDocumentService::class)->previewUrl($driver->user?->avatar_path ?? null),
            'dateOfBirth' => optional($driver->user?->date_of_birth)->toDateString() ?? $driver->user?->date_of_birth,
            'city' => $driver->city,
            'stateId' => $driver->user?->state_id ?? $driver->state_id ?? null,
            'districtId' => $driver->user?->district_id ?? $driver->district_id ?? null,
            'driverType' => $driver->fleet_owner_id ? 'fleet_driver' : 'individual_driver',
            'fleetOwnerId' => $driver->fleet_owner_id,
            'fleet' => $driver->fleet_owner_id && Schema::hasTable('fleet_owners')
                ? DB::table('fleet_owners')->where('id', $driver->fleet_owner_id)->first()
                : null,
            'vehicleFamily' => $driver->vehicle_family,
            'aadhaarLast4' => $driver->aadhaar_last4,
            'panLast4' => $driver->pan_last4,
            'licenseNo' => $driver->license_no,
            'licenseExpiresAt' => $driver->license_expires_at,
            'online' => $online,
            'dutyStatus' => $duty,
            'dutyLabel' => ucfirst(str_replace('_', ' ', (string) $duty)),
            'kycStatus' => $kycStatus,
            'kycRejectedReason' => $driver->kyc_rejected_reason ?? null,
            'submittedAt' => Schema::hasColumn('drivers', 'application_submitted_at')
                ? optional($driver->application_submitted_at)?->toIso8601String()
                : null,
            'applicationSubmittedAt' => Schema::hasColumn('drivers', 'application_submitted_at')
                ? optional($driver->application_submitted_at)?->toIso8601String()
                : null,
            'canGoOnline' => $canGoOnline,
            'canReceiveOffers' => $canReceive,
            'offerBlockReason' => $canReceive ? null : ($kyc === 'left_fleet' ? 'Contact admin to complete KYC.' : ($fleetUnassigned ? 'You are not assigned to a vehicle.' : ($blocked ? 'Account is blocked' : ($approved ? 'Tap Go online when you are ready.' : 'Complete KYC onboarding to go online.')))),
            'nextStep' => $kyc === 'left_fleet' || $fleetUnassigned ? 'KYC_BLOCKED' : ($approved ? 'HOME' : ($kyc === 'under_review' ? 'KYC_REVIEW' : ($blocked ? 'KYC_BLOCKED' : 'KYC'))),
            'ratingAvg' => (float) $rating['average'],
            'ratingCount' => (int) $rating['count'],
            'vehicles' => $driver->vehicles,
            'documents' => $documents,
            'today' => $todayStats,
            'pendingRequests' => $pending,
            'unreadNotifications' => count(array_filter($inbox, fn ($row) => empty($row['read']))),
            'notifications' => $inbox,
            'wallet' => array_merge(
                app(WalletAccountService::class)->snapshot($wallet, 'DRIVER'),
                ['balancePaise' => $balance, 'balanceRupees' => $balance / 100],
            ),
            ...$this->welcomeBonusPayload($driver),
            'gstPercent' => app(RideSettingsService::class)->gstPercent(),
            'fleetOwnerCommissionPercent' => app(RideSettingsService::class)->fleetOwnerCommissionPercent(),
            'incentives' => $incentives,
            'referralCode' => app(IncentiveService::class)->ensureReferralCode($actor),
            'documentAlerts' => collect($documents)->filter(fn ($doc) => in_array($doc['status'], ['expired', 'rejected'], true) || ($doc['expiresAt'] && $doc['expiresAt'] <= now()->toDateString()))->values()->all(),
            'vehicle' => $vehicle ? [
                'registrationNo' => $vehicle->registration_no,
                'category' => $vehicle->category,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'status' => $vehicle->status,
            ] : null,
            'activeJob' => $activeRide
                ? array_merge(app(\App\Services\BookingService::class)->present($activeRide, $actor), ['kind' => 'ride'])
                : ($activeParcel ? ['kind' => 'parcel', 'id' => (string) $activeParcel->id, 'status' => $activeParcel->status] : null),
            'territory' => TerritoryContacts::forUser($actor),
        ];
    }

    public function offers(User $actor): array
    {
        $dash = $this->dashboard($actor);
        $rides = [];
        $parcels = [];
        if ($dash['canReceiveOffers']) {
            $rides = app(BookingService::class)->offersForDriver($actor);
            if (Schema::hasTable('parcel_shipments')) {
                $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->first();
                $lat = $actor->last_lat !== null ? (float) $actor->last_lat : null;
                $lng = $actor->last_lng !== null ? (float) $actor->last_lng : null;
                $radius = app(\App\Services\RideSettingsService::class)->radiusKm();
                $dismissals = app(OfferDismissal::class);
                $parcels = DB::table('parcel_shipments')
                    ->whereNull('driver_id')
                    ->when(Schema::hasColumn('parcel_shipments', 'booking_id'), fn ($q) => $q->whereNull('booking_id'))
                    ->whereIn('status', ['created', 'paid', 'CREATED', 'PAID'])
                    ->where('created_at', '>=', now()->subHours(12))
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get()
                    ->filter(function ($row) use ($driver, $lat, $lng, $radius, $dismissals) {
                        if ($driver && $dismissals->hidden((int) $driver->id, 'parcel', (string) $row->id)) {
                            return false;
                        }
                        $vehicleCat = strtoupper((string) ($driver?->vehicles?->first()?->category ?? ''));
                        if ($vehicleCat !== '' && ! \App\Support\VehicleCategory::parcelCompatible($vehicleCat, (string) ($row->category ?? ''))) {
                            return false;
                        }
                        if ($driver && Schema::hasColumn('parcel_shipments', 'pickup_lat') && $row->pickup_lat && $lat !== null) {
                            $km = \App\Support\Geo::haversineKm($lat, $lng, (float) $row->pickup_lat, (float) $row->pickup_lng);
                            if ($km > $radius) {
                                return false;
                            }
                        }

                        return true;
                    })
                    ->map(fn ($row) => array_merge(app(AppSurfaceService::class)->presentParcel($row, $actor), [
                        'actions' => ['accept', 'reject'],
                    ]))
                    ->values()
                    ->all();
            }
        }
        $scheduled = $this->scheduledOffers($actor);
        $merged = [];
        foreach (array_merge($rides, $parcels, $scheduled) as $row) {
            $key = ($row['kind'] ?? 'ride').':'.($row['id'] ?? '');
            $merged[$key] = $row;
        }

        return ['offers' => array_values($merged)];
    }

    public function trips(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $rides = \App\Models\Booking::query()->where('driver_id', $driver->id)->orderByDesc('id')->limit(40)->get()->map(function ($row) {
            $stars = null;
            if (Schema::hasTable('booking_ratings')) {
                $q = DB::table('booking_ratings')->where('booking_id', $row->id);
                if (Schema::hasColumn('booking_ratings', 'from_role')) {
                    $q->where('from_role', 'CUSTOMER');
                }
                $stars = $q->avg('stars');
            }

            $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
            $product = app(BookingService::class)->presentedProduct($row);
            $advancePaid = (int) ($snapshot['advancePaidPaise'] ?? 0);
            $total = (int) ($row->final_fare_paise ?? $row->quote_paise);
            $commissionPaise = (int) ($row->commission_paise ?? 0);
            $earningPaise = (int) ($row->driver_earning_paise ?? max(0, $total - $commissionPaise));
            $fareDetail = TripFareDetail::fromRecord($row, $snapshot, $total, $commissionPaise, $earningPaise);
            $phone = null;
            $needsAdvance = ! empty($snapshot['advanceAfterAccept']) && $advancePaid < 1 && $product !== 'LOCAL_CAB';
            $cityRide = $product === 'LOCAL_CAB';
            $canActivate = (string) $row->status === 'SCHEDULED'
                && $row->scheduled_at
                && (! $needsAdvance || $cityRide)
                && \Illuminate\Support\Carbon::parse($row->scheduled_at)->lte(now()->addMinutes(45));
            if ((string) $row->status === 'SCHEDULED' && $row->customer_id) {
                $phone = DB::table('users')->where('id', $row->customer_id)->value('phone');
            }
            $mode = strtoupper((string) ($row->payment_mode ?? 'CASH'));
            $actions = [];
            if ((string) $row->status === 'SCHEDULED') {
                $actions[] = 'call';
                if ($cityRide || $canActivate) {
                    $actions[] = 'activate';
                }
                if ($cityRide || $advancePaid < 1) {
                    $actions[] = 'cancel';
                }
            }

            return [
                'kind' => 'ride',
                'id' => (string) $row->id,
                'publicRef' => $row->public_ref,
                'status' => $row->status,
                'lifecycle' => \App\Support\BookingStatus::lifecycle((string) $row->status),
                'pickupText' => $row->pickup_text,
                'dropText' => $row->drop_text,
                'product' => $product,
                'serviceType' => strtoupper((string) $row->product) === 'SCHEDULE'
                    ? TripFareDetail::serviceLabel($product)
                    : ($fareDetail['serviceLabel'] ?? TripFareDetail::serviceLabel($product)),
                'category' => $row->category,
                'quotePaise' => $total,
                'quoteRupees' => $total / 100,
                'collectRupees' => $total / 100,
                'commissionRupees' => $commissionPaise / 100,
                'earningRupees' => $earningPaise / 100,
                'estimatedEarningsRupees' => $earningPaise / 100,
                'bookedDistanceKm' => isset($snapshot['bookedDistanceKm']) ? (float) $snapshot['bookedDistanceKm'] : ($row->distance_km !== null ? (float) $row->distance_km : null),
                'distanceKm' => $row->distance_km !== null ? (float) $row->distance_km : null,
                'actualDistanceKm' => $row->actual_distance_km !== null ? (float) $row->actual_distance_km : null,
                'durationSeconds' => $row->duration_seconds ?? null,
                'waitMinutes' => $row->wait_minutes ?? ($fareDetail['waitingMinutes'] ?? 0),
                'waitingMinutes' => $fareDetail['waitingMinutes'] ?? 0,
                'waitingLabel' => $fareDetail['waitingLabel'] ?? null,
                'waitingChargeRupees' => $fareDetail['waitingRupees'] ?? 0,
                'fareDetail' => $fareDetail,
                'paymentMode' => $row->payment_mode ?? 'CASH',
                'paymentType' => $mode === 'WALLET' ? 'Wallet' : ($mode === 'UPI' ? 'Online / PayU' : 'Cash'),
                'amountType' => $mode === 'WALLET' ? 'Wallet' : ($mode === 'UPI' ? 'Online / PayU' : 'Cash'),
                'scheduledAt' => optional($row->scheduled_at)?->toIso8601String(),
                'cityMode' => $cityRide
                    ? (string) ($snapshot['cityMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'oneWayMode' => $product === 'ONE_WAY'
                    ? (string) ($snapshot['oneWayMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'multiStopMode' => $product === 'MULTI_STOP'
                    ? (string) ($snapshot['multiStopMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'stops' => $product === 'MULTI_STOP' ? ($snapshot['multiStops'] ?? $snapshot['stops'] ?? []) : [],
                'stopCount' => $snapshot['stopCount'] ?? null,
                'totalKm' => isset($snapshot['totalKm']) ? (float) $snapshot['totalKm'] : null,
                'currentStopIndex' => isset($snapshot['currentStopIndex']) ? (int) $snapshot['currentStopIndex'] : 0,
                'routePolyline' => $snapshot['routePolyline'] ?? null,
                'airportMode' => $product === 'AIRPORT'
                    ? (string) ($snapshot['airportMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'airportDirection' => $snapshot['airportDirection'] ?? null,
                'airportName' => $snapshot['airportName'] ?? null,
                'railwayMode' => $product === 'RAILWAY'
                    ? (string) ($snapshot['railwayMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'railwayDirection' => $snapshot['railwayDirection'] ?? null,
                'stationName' => $snapshot['stationName'] ?? null,
                'trainNumber' => $row->train_number ?: ($snapshot['trainNo'] ?? null),
                'trainName' => trim((string) ($snapshot['trainName'] ?? '')) !== '' ? $snapshot['trainName'] : null,
                'flightNumber' => $row->flight_number ?: ($snapshot['flightNo'] ?? null),
                'terminal' => $row->terminal ?: ($snapshot['terminal'] ?? null),
                'parcelMode' => $product === 'PARCEL'
                    ? (string) ($snapshot['parcelMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'parcelType' => $snapshot['parcelType'] ?? null,
                'receiverName' => $snapshot['receiverName'] ?? $row->passenger_name,
                'packageNotes' => $snapshot['packageNotes'] ?? $row->instructions,
                'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
                'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
                'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
                'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
                'roundTripMode' => $product === 'ROUND_WAY'
                    ? (string) ($snapshot['roundTripMode'] ?? ($row->scheduled_at ? 'schedule' : 'now'))
                    : null,
                'roundTripPhase' => $snapshot['roundTripPhase'] ?? null,
                'oneSideCompletedAt' => $snapshot['oneSideCompletedAt'] ?? $snapshot['outboundCompletedAt'] ?? null,
                'outboundCompletedAt' => $snapshot['outboundCompletedAt'] ?? $snapshot['oneSideCompletedAt'] ?? null,
                'returnStartedAt' => $snapshot['returnStartedAt'] ?? null,
                'returnCompletedAt' => $snapshot['returnCompletedAt'] ?? null,
                'waitingRate' => (int) ($snapshot['waitingRate'] ?? $snapshot['waitingPaisePerMin'] ?? 0),
                'waitingPaisePerMin' => (int) ($snapshot['waitingPaisePerMin'] ?? $snapshot['waitingRate'] ?? 0),
                'baseRoundTripPaise' => (int) ($snapshot['baseRoundTripPaise'] ?? 0),
                'gstPercent' => (float) ($snapshot['gstPercent'] ?? 0),
                'gstAppliesToFare' => ! empty($snapshot['gstAppliesToFare']),
                'returnAt' => optional($row->return_at)?->toIso8601String(),
                'tripStartedAt' => optional($row->trip_started_at)?->toIso8601String(),
                'tripEndedAt' => optional($row->trip_ended_at)?->toIso8601String(),
                'updatedAt' => optional($row->updated_at)?->toIso8601String(),
                'customerRating' => $stars !== null ? round((float) $stars, 1) : null,
                'prebook' => (bool) ($snapshot['prebook'] ?? false),
                'advancePaidPaise' => $advancePaid,
                'balanceRupees' => max(0, $total - (int) ($snapshot['advancePaise'] ?? 0)) / 100,
                'needsAdvance' => $needsAdvance,
                'canActivate' => $canActivate,
                'customerPhone' => $phone,
                'actions' => $actions,
            ];
        })->all();
        $parcels = [];
        if (Schema::hasTable('parcel_shipments')) {
            $parcels = DB::table('parcel_shipments')
                ->where('driver_id', $driver->id)
                ->when(Schema::hasColumn('parcel_shipments', 'booking_id'), fn ($q) => $q->whereNull('booking_id'))
                ->orderByDesc('id')
                ->limit(40)
                ->get()
                ->map(fn ($row) => [
                'kind' => 'parcel',
                'id' => (string) $row->id,
                'status' => $row->status,
                'pickupText' => $row->pickup_text,
                'dropText' => $row->drop_text,
                'product' => 'PARCEL',
                'category' => $row->category,
                'quotePaise' => (int) ($row->quote_paise ?? 0),
                'quoteRupees' => ((int) ($row->quote_paise ?? 0)) / 100,
                'collectRupees' => ((int) ($row->quote_paise ?? 0)) / 100,
                'commissionRupees' => ((int) ($row->commission_paise ?? max(0, (int) ($row->quote_paise ?? 0) - (int) ($row->driver_earning_paise ?? 0)))) / 100,
                'earningRupees' => ((int) ($row->driver_earning_paise ?? (int) round(((int) ($row->quote_paise ?? 0)) * 0.9))) / 100,
                'updatedAt' => $row->updated_at,
            ])->all();
        }

        $tours = [];
        try {
            $tours = app(\App\Services\Tour\TourBookingService::class)->driverCards($actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('tour.driver_trips_failed', ['error' => $e->getMessage()]);
        }
        $bulk = [];
        try {
            $bulk = app(\App\Services\Bulk\BulkBookingService::class)->driverCards($actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('bulk.driver_trips_failed', ['error' => $e->getMessage()]);
        }
        $corporate = [];
        try {
            $corporate = app(\App\Services\Corporate\CorporatePlanService::class)->driverCards($actor);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('corporate.driver_trips_failed', ['error' => $e->getMessage()]);
        }

        return ['trips' => array_values(array_merge($rides, $parcels, $tours, $bulk, $corporate))];
    }

    public function documents(User $actor): array
    {
        $dash = $this->dashboard($actor);

        return ['documents' => $dash['documents'], 'alerts' => $dash['documentAlerts']];
    }

    public function earnings(User $actor): array
    {
        $dash = $this->dashboard($actor);
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $wallets = app(WalletAccountService::class);
        $items = $wallets->withdrawalsFor((int) $actor->id);
        $ist = now()->timezone('Asia/Kolkata');
        $week = $this->periodEarnings((int) $driver->id, (int) $actor->id, $ist->copy()->startOfWeek()->utc(), now());
        $month = $this->periodEarnings((int) $driver->id, (int) $actor->id, $ist->copy()->startOfMonth()->utc(), now());
        $cash = [];
        if (Schema::hasTable('wallet_ledger')) {
            $cash = DB::table('wallet_ledger')
                ->where('owner_user_id', $actor->id)
                ->whereIn('kind', ['cash_trip', 'cash', 'commission', 'trip'])
                ->orderByDesc('id')
                ->limit(80)
                ->get()
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'bookingId' => $row->booking_id ? (string) $row->booking_id : null,
                    'kind' => $row->kind,
                    'direction' => strtoupper((string) ($row->direction ?? 'CREDIT')),
                    'note' => $row->note,
                    'grossRupees' => ((int) ($row->gross_paise ?? $row->amount_paise)) / 100,
                    'amountRupees' => ((int) $row->amount_paise) / 100,
                    'commissionRupees' => ((int) ($row->commission_paise ?? 0)) / 100,
                    'earningRupees' => ((int) $row->amount_paise) / 100,
                    'createdAt' => $row->created_at,
                ])->all();
        }

        return [
            'today' => $dash['today'],
            'week' => $week,
            'month' => $month,
            'cashTransactions' => $cash,
            'wallet' => $dash['wallet'],
            'withdrawalsEnabled' => true,
            'gstPercent' => app(RideSettingsService::class)->gstPercent(),
            'withdrawals' => array_merge(app(RideSettingsService::class)->walletCashPolicy(), [
                'supported' => true,
                'minRupees' => app(RideSettingsService::class)->driverWithdrawMinRupees(),
                'items' => $items,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodEarnings(int $driverId, int $userId, $from, $to): array
    {
        $rides = \App\Models\Booking::query()
            ->where('driver_id', $driverId)
            ->whereIn('status', ['COMPLETED', 'completed'])
            ->where(function ($query) use ($from, $to) {
                if (Schema::hasColumn('bookings', 'trip_ended_at')) {
                    $query->whereBetween('trip_ended_at', [$from, $to])
                        ->orWhere(function ($fallback) use ($from, $to) {
                            $fallback->whereNull('trip_ended_at')->whereBetween('updated_at', [$from, $to]);
                        });
                } else {
                    $query->whereBetween('updated_at', [$from, $to]);
                }
            })
            ->get();
        $gross = 0;
        $earn = 0;
        $commission = 0;
        $cashGross = 0;
        foreach ($rides as $row) {
            $fare = (int) ($row->final_fare_paise ?? $row->quote_paise ?? 0);
            $comm = (int) ($row->commission_paise ?? 0);
            $net = (int) ($row->driver_earning_paise ?? max(0, $fare - $comm));
            $gross += $fare;
            $commission += $comm;
            $earn += $net;
            $mode = strtoupper((string) ($row->payment_mode ?? 'CASH'));
            if (in_array($mode, ['CASH', 'COD', 'CASH_ON_DELIVERY'], true)) {
                $cashGross += $fare;
            }
        }
        $parcelCount = 0;
        $parcelEarn = 0;
        $parcelGross = 0;
        if (Schema::hasTable('parcel_shipments')) {
            $parcels = DB::table('parcel_shipments')
                ->where('driver_id', $driverId)
                ->whereIn('status', ['delivered', 'DELIVERED'])
                ->where('updated_at', '>=', $from)
                ->where('updated_at', '<=', $to)
                ->get();
            $parcelCount = $parcels->count();
            foreach ($parcels as $row) {
                $fare = (int) ($row->quote_paise ?? 0);
                $parcelGross += $fare;
                $parcelEarn += (int) ($row->driver_earning_paise ?? (int) round($fare * 0.9));
            }
        }
        $incentives = 0;
        if (Schema::hasTable('wallet_ledger')) {
            $incentives = (int) DB::table('wallet_ledger')
                ->where('owner_user_id', $userId)
                ->whereIn('direction', ['credit', 'CREDIT'])
                ->whereIn('kind', ['bonus', 'incentive', 'joining', 'referral'])
                ->where('created_at', '>=', $from)
                ->where('created_at', '<=', $to)
                ->sum('amount_paise');
        }
        $net = $earn + $parcelEarn;

        return [
            'earningsPaise' => $net,
            'netPaise' => $net,
            'earningsRupees' => $net / 100,
            'netRupees' => $net / 100,
            'grossPaise' => $gross + $parcelGross,
            'grossRupees' => ($gross + $parcelGross) / 100,
            'commissionPaise' => $commission,
            'commissionRupees' => $commission / 100,
            'cashCollectedPaise' => $cashGross,
            'cashCollectedRupees' => $cashGross / 100,
            'incentivesPaise' => $incentives,
            'incentivesRupees' => $incentives / 100,
            'trips' => $rides->count() + $parcelCount,
            'rides' => $rides->count(),
            'parcels' => $parcelCount,
        ];
    }

    public function setOnline(User $actor, bool $online): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        if ($online && in_array(strtolower((string) $driver->duty_status), ['on_trip', 'on_delivery'], true)) {
            return $this->me($actor);
        }
        if ($online) {
            $kyc = strtolower((string) ($driver->kyc_status ?? ''));
            abort_if($kyc === 'left_fleet', 403, 'Contact admin to complete KYC before you can go online.');
            abort_unless(in_array($kyc, ['verified', 'approved', 'active'], true), 422, 'Admin must approve your KYC before you can go online.');
            if ($driver->fleet_owner_id) {
                abort_unless($driver->vehicles()->exists(), 422, 'You are not assigned to a vehicle.');
            }
        }
        if (! $online && in_array(strtolower((string) $driver->duty_status), ['on_trip', 'on_delivery'], true)) {
            abort(422, 'Complete the trip before going offline.');
        }
        $driver->update([
            'online' => $online,
            'duty_status' => $online ? 'online' : 'offline',
        ]);
        if ($online) {
            try {
                app(BookingService::class)->offerWaitingToDriver($actor->fresh() ?? $actor);
            } catch (\Throwable $e) {
                Log::warning('booking.offer_on_online_failed', ['userId' => $actor->id, 'error' => $e->getMessage()]);
            }
        }

        return $this->me($actor);
    }

    public function pingLocation(User $actor, float $lat, float $lng, ?float $heading = null, ?string $bookingId = null): array
    {
        abort_unless($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180, 422, 'Unable to update driver location.');
        $payload = [
            'last_lat' => $lat,
            'last_lng' => $lng,
            'location_updated_at' => now(),
        ];
        if ($heading !== null && \Illuminate\Support\Facades\Schema::hasColumn('users', 'last_heading')) {
            $payload['last_heading'] = $heading;
        }
        User::query()->where('id', $actor->id)->update($payload);
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if ($driver) {
            Vehicle::query()->where('driver_id', $driver->id)->update([
                'last_lat' => $lat,
                'last_lng' => $lng,
                'last_fix_at' => now(),
            ]);
        }
        $allowed = \App\Support\ServiceArea::resolve($lat, $lng, null, null);
        $live = null;
        if ($bookingId) {
            try {
                app(BookingService::class)->trackTripProgress($actor, $bookingId, $lat, $lng);
                $live = app(BookingService::class)->live($actor, $bookingId);
            } catch (\Throwable $e) {
                Log::warning('booking.live_after_location_failed', ['message' => $e->getMessage()]);
            }
        }
        if ($driver && $driver->online && in_array(strtolower((string) $driver->duty_status), ['online', 'available'], true)) {
            try {
                app(BookingService::class)->offerWaitingToDriver($actor, $lat, $lng);
            } catch (\Throwable $e) {
                Log::warning('booking.offer_on_ping_failed', ['userId' => $actor->id, 'error' => $e->getMessage()]);
            }
        }

        return [
            'ok' => true,
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'bookingId' => $bookingId,
            'allowed' => $allowed['allowed'],
            'comingSoon' => $allowed['comingSoon'],
            'serviceArea' => $allowed['state'],
            'detectedState' => $allowed['state'],
            'message' => $allowed['message'] ?? ($allowed['allowed'] ? null : \App\Support\ServiceArea::comingSoonMessage($allowed['state'])),
            'recordedAt' => now()->toIso8601String(),
            'live' => $live,
        ];
    }

    public function list(User $actor)
    {
        $q = Driver::query()->with('user')->orderByDesc('id')->limit(100);
        if (! in_array($actor->role, ['ADMIN', 'SUPER_ADMIN', 'STATE_HEAD', 'DISTRICT_HEAD', 'FLEET_OWNER'], true)) {
            $q->where('user_id', $actor->id);
        }

        return ['drivers' => $q->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => $row->user?->name,
            'kycStatus' => $row->kyc_status,
            'online' => (bool) $row->online,
            'dutyStatus' => $row->duty_status,
        ])->all()];
    }

    public function vehicles(User $actor)
    {
        if ($actor->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $actor->id)->first();

            return ['vehicles' => Vehicle::query()->where('driver_id', $driver?->id)->get()];
        }

        return ['vehicles' => Vehicle::query()->orderByDesc('id')->limit(100)->get()];
    }

    public function kycCatalog(): array
    {
        return app(KycDocumentService::class)->catalog();
    }

    public function patchProfile(User $actor, array $data): array
    {
        $name = trim(implode(' ', array_filter([
            $data['firstName'] ?? null,
            $data['lastName'] ?? null,
        ])));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '') {
            abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Enter a valid email address');
            abort_if($this->identityTaken('email', $email, $actor->id), 409, 'Email already registered');
        }
        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? $data['mobile'] ?? '')) ?? '';
        if (strlen($phone) === 10) {
            $phone = '91'.$phone;
        }
        if ($phone !== '') {
            abort_if($this->identityTaken('phone', $phone, $actor->id), 409, 'Mobile number already registered');
        }
        $emailChanged = $email !== '' && strtolower((string) $actor->email) !== $email;
        $userPatch = array_filter([
            'name' => $data['name'] ?? ($name !== '' ? $name : null),
            'email' => $email !== '' ? $email : null,
            'emergency_name' => $data['emergencyName'] ?? null,
            'emergency_phone' => $data['emergencyPhone'] ?? null,
            'last_address' => $data['address'] ?? null,
            'gender' => isset($data['gender']) ? strtoupper((string) $data['gender']) : null,
            'date_of_birth' => $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $stateId = isset($data['stateId']) || isset($data['state_id'])
            ? (int) ($data['stateId'] ?? $data['state_id'])
            : null;
        $districtId = isset($data['districtId']) || isset($data['district_id'])
            ? (int) ($data['districtId'] ?? $data['district_id'])
            : null;
        if ($stateId && Schema::hasColumn('users', 'state_id')) {
            $userPatch['state_id'] = $stateId;
        }
        if ($districtId && Schema::hasColumn('users', 'district_id')) {
            $userPatch['district_id'] = $districtId;
        }
        if ($userPatch) {
            $actor->update($userPatch);
        }
        if ($emailChanged) {
            app(AuthService::class)->sendWelcomeMail($actor->fresh(), 'profile-mail:'.$email);
        }

        $city = $data['city'] ?? null;
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if (! $driver) {
            $driver = Driver::query()->create([
                'user_id' => $actor->id,
                'license_no' => 'PENDING',
                'kyc_status' => 'pending',
                'duty_status' => 'offline',
            ]);
        }
        if ($driver && ! empty($data['welcomeBonusSeen']) && Schema::hasColumn('drivers', 'welcome_bonus_seen_at')) {
            $driver->update(['welcome_bonus_seen_at' => now()]);
        }
        if ($driver) {
            if ($districtId && Schema::hasTable('districts')) {
                $city = $city ?: DB::table('districts')->where('id', $districtId)->value('name');
            }
            $license = strtoupper(trim((string) ($data['licenseNo'] ?? $data['license_no'] ?? '')));
            if ($license !== '' && ! in_array($license, ['PENDING', 'NA'], true)) {
                abort_if($this->identityTaken('license', $license, $actor->id), 409, 'Driving licence already registered');
            }
            $driverUpdates = array_filter([
                'city' => $city,
                'license_no' => $license !== '' ? $license : ($data['licenseNo'] ?? $data['license_no'] ?? null),
                'license_expires_at' => $data['licenseExpiresAt'] ?? $data['license_expires_at'] ?? null,
                'vehicle_family' => isset($data['vehicleFamily']) ? strtoupper((string) $data['vehicleFamily']) : ($data['vehicle_family'] ?? null),
                'bank_account_holder' => $data['accountHolder'] ?? $data['bank_account_holder'] ?? null,
                'bank_ifsc' => isset($data['ifsc']) ? strtoupper((string) $data['ifsc']) : null,
                'upi_id' => $data['upiId'] ?? $data['upi_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($stateId && Schema::hasColumn('drivers', 'state_id')) {
                $driverUpdates['state_id'] = $stateId;
            }
            if ($districtId && Schema::hasColumn('drivers', 'district_id')) {
                $driverUpdates['district_id'] = $districtId;
            }
            if (! empty($data['aadhaar']) || ! empty($data['aadhaarNumber'])) {
                $aadhaar = preg_replace('/\D+/', '', (string) ($data['aadhaar'] ?? $data['aadhaarNumber'])) ?? '';
                abort_unless(preg_match('/^\d{12}$/', $aadhaar), 422, 'Enter a valid 12-digit Aadhaar number');
                abort_if($this->identityTaken('aadhaar', hash('sha256', $aadhaar), $actor->id), 409, 'Aadhaar number already registered');
                $driverUpdates['aadhaar_last4'] = substr($aadhaar, -4);
                $driverUpdates['aadhaar_hash'] = hash('sha256', $aadhaar);
                $driverUpdates['id_type'] = 'AADHAAR';
                $driverUpdates['id_last4'] = substr($aadhaar, -4);
            }
            if (! empty($data['pan']) || ! empty($data['panNumber'])) {
                $pan = strtoupper(preg_replace('/\s+/', '', (string) ($data['pan'] ?? $data['panNumber'])) ?? '');
                abort_unless(preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan), 422, 'Enter a valid PAN');
                abort_if($this->identityTaken('pan', hash('sha256', $pan), $actor->id), 409, 'PAN already registered');
                $driverUpdates['pan_last4'] = substr($pan, -4);
                $driverUpdates['pan_hash'] = hash('sha256', $pan);
            }
            if (! empty($data['accountNumber'])) {
                $acct = preg_replace('/\D+/', '', (string) $data['accountNumber']) ?? '';
                $driverUpdates['bank_account_last4'] = substr($acct, -4);
                $driverUpdates['bank_account_hash'] = hash('sha256', $acct);
            }
            if (! empty($data['termsAccepted']) && Schema::hasColumn('drivers', 'terms_accepted_at')) {
                $driverUpdates['terms_accepted_at'] = now();
            }
            if ($driverUpdates) {
                $driver->update($driverUpdates);
            }
            $this->upsertVehicle($driver, $data);
        }

        try {
            return $this->kycSnapshot($actor->fresh());
        } catch (\Throwable $e) {
            Log::warning('driver.profile_snapshot_failed', ['userId' => $actor->id, 'error' => $e->getMessage()]);

            return [
                'ok' => true,
                'kycStatus' => $driver->kyc_status ?? 'pending',
                'nextStep' => 'KYC',
                'stateId' => $stateId ?: ($actor->state_id ?? null),
                'districtId' => $districtId ?: ($actor->district_id ?? null),
                'city' => $city ?? $data['city'] ?? null,
            ];
        }
    }

    public function submitKyc(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles', 'documents', 'user')->firstOrFail();
        $kyc = strtolower((string) ($driver->kyc_status ?? 'pending'));
        abort_if($kyc === 'under_review', 422, 'Your application is already under review.');
        abort_if(in_array($kyc, ['verified', 'approved', 'active'], true), 422, 'Onboarding is already approved.');

        $catalog = app(KycDocumentService::class)->catalog();
        $required = $catalog['requiredDocs'] ?? [];
        $family = strtoupper((string) ($driver->vehicle_family ?? $driver->vehicles->first()?->category ?? ''));
        if (in_array($family, $catalog['permitRequiredFor'] ?? [], true) || in_array($family, ['AUTO', 'CAR', 'LOADING', 'SEDAN', 'SUV', 'MINI', 'TRAVELLER'], true)) {
            $required[] = 'PERMIT';
        }
        $have = $driver->documents->pluck('type')->map(fn ($t) => strtoupper((string) $t))->all();
        if (in_array('SELFIE', $have, true) && ! in_array('LIVE_PHOTO', $have, true)) {
            $have[] = 'LIVE_PHOTO';
        }
        $missing = [];
        foreach ($required as $type) {
            if (! in_array($type, $have, true)) {
                $missing[] = $type;
            }
        }
        abort_unless($driver->vehicles->isNotEmpty(), 422, 'Add vehicle information before submitting.');
        abort_if($missing !== [], 422, 'Complete these items before submitting: '.implode(', ', $missing));

        $driver->update(array_filter([
            'kyc_status' => 'under_review',
            'application_submitted_at' => Schema::hasColumn('drivers', 'application_submitted_at') ? now() : null,
        ], fn ($v) => $v !== null));

        $this->sendOnboardingSubmittedMail($actor->fresh() ?? $actor);

        return $this->me($actor);
    }

    public function kycSnapshot(User $actor): array
    {
        return $this->me($actor);
    }

    private function sendOnboardingSubmittedMail(User $actor): void
    {
        $email = strtolower(trim((string) $actor->email));
        $title = 'Onboarding submitted successfully';
        $body = 'Your KarnaRide driver onboarding was submitted successfully. We are reviewing your documents. You will receive another email when your account is active.';
        if ($email === '' || str_ends_with($email, '@otp.karnacab.local')) {
            $this->logMailDelivery((int) $actor->id, 'kyc_submitted', $title, $body, 'skipped', 'no_email');

            return;
        }
        try {
            Mail::to($email)->send(new EventNoticeMail($title, $body));
            $this->logMailDelivery((int) $actor->id, 'kyc_submitted', $title, $body, 'sent', 'smtp');
            Log::info('kyc.submitted_mail_sent', ['userId' => $actor->id]);
        } catch (\Throwable $e) {
            $this->logMailDelivery((int) $actor->id, 'kyc_submitted', $title, $body, 'failed', substr($e->getMessage(), 0, 150));
            Log::warning('kyc.submitted_mail_failed', ['userId' => $actor->id, 'error' => $e->getMessage()]);
        }
    }

    private function logMailDelivery(int $userId, string $event, string $title, string $body, string $status, string $note): void
    {
        if (! Schema::hasTable('notification_deliveries')) {
            return;
        }
        DB::table('notification_deliveries')->insert([
            'user_id' => $userId,
            'event' => $event,
            'channel' => 'email',
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'provider_note' => $note,
            'entity_type' => 'kyc',
            'entity_id' => (string) $userId,
            'created_at' => now(),
        ]);
    }

    public function reviewKyc(string $driverId, string $status, ?string $reason = null): array
    {
        $driver = Driver::query()->findOrFail($driverId);
        $normalized = $status === 'verified' ? 'approved' : $status;
        $driver->update([
            'kyc_status' => $normalized,
            'kyc_rejected_reason' => $reason,
        ]);
        if (in_array($normalized, ['approved', 'verified', 'active'], true)) {
            User::query()->where('id', $driver->user_id)->update(['status' => 'ACTIVE']);
            $this->grantWelcomeBonus($driver->fresh() ?? $driver);
        }

        return ['id' => (string) $driver->id, 'kycStatus' => $driver->kyc_status];
    }

    public function welcomeBonusPayload(?Driver $driver): array
    {
        $user = $driver?->user;
        $offer = $user
            ? app(TerritoryBonusService::class)->forUser($user, 'joining')
            : app(TerritoryBonusService::class)->resolve('DRIVER', 'joining', null, null);
        $rupees = $driver && ((int) ($driver->welcome_bonus_paise ?? 0)) > 0
            ? (int) round(((int) $driver->welcome_bonus_paise) / 100)
            : (int) $offer['amountRupees'];

        return [
            'welcomeBonusPending' => $driver ? $this->welcomeBonusPending($driver) : false,
            'welcomeBonusRupees' => $rupees,
            'welcomeBonusEnabled' => ! empty($offer['enabled']) && $rupees > 0,
            'referralBonusRupees' => $user
                ? (int) app(TerritoryBonusService::class)->forUser($user, 'referral')['amountRupees']
                : 0,
        ];
    }

    private function welcomeBonusPending(Driver $driver): bool
    {
        $user = $driver->user;
        $offer = $user
            ? app(TerritoryBonusService::class)->forUser($user, 'joining')
            : ['enabled' => app(RideSettingsService::class)->driverWelcomeBonusEnabled()];
        if (empty($offer['enabled'])) {
            return false;
        }
        if (! Schema::hasColumn('drivers', 'welcome_bonus_at') || empty($driver->welcome_bonus_at)) {
            return false;
        }
        if (Schema::hasColumn('drivers', 'welcome_bonus_seen_at') && ! empty($driver->welcome_bonus_seen_at)) {
            return false;
        }

        return true;
    }

    public function grantWelcomeBonus(Driver $driver): void
    {
        $kyc = strtolower((string) ($driver->kyc_status ?? ''));
        if (! in_array($kyc, ['approved', 'verified', 'active'], true)) {
            return;
        }
        $user = User::query()->find((int) $driver->user_id);
        $offer = $user
            ? app(TerritoryBonusService::class)->forUser($user, 'joining')
            : app(TerritoryBonusService::class)->resolve('DRIVER', 'joining', null, null);
        $alreadyJoined = Schema::hasColumn('drivers', 'welcome_bonus_at') && $driver->welcome_bonus_at;
        $rupees = (int) ($offer['amountRupees'] ?? 0);
        $paise = $rupees * 100;
        $userId = (int) $driver->user_id;
        if (! empty($offer['enabled']) && $rupees >= 1 && ! $alreadyJoined) {
            if (Schema::hasTable('incentives') && DB::table('incentives')->where('user_id', $userId)->where('kind', 'driver_joining')->where('status', 'paid')->exists()) {
                if (Schema::hasColumn('drivers', 'welcome_bonus_at')) {
                    $driver->update(array_filter([
                        'welcome_bonus_paise' => Schema::hasColumn('drivers', 'welcome_bonus_paise') ? $paise : null,
                        'welcome_bonus_at' => now(),
                    ]));
                }
            } else {
                $wallets = app(WalletAccountService::class);
                $wallets->creditBonus($userId, 'DRIVER', $paise, 'joining_bonus', 'Joining bonus ₹'.$rupees.' credited to wallet');
                $wallets->reconcileJoiningBonus($userId, 'DRIVER', $paise);
                $patch = [];
                if (Schema::hasColumn('drivers', 'welcome_bonus_paise')) {
                    $patch['welcome_bonus_paise'] = $paise;
                }
                if (Schema::hasColumn('drivers', 'welcome_bonus_at')) {
                    $patch['welcome_bonus_at'] = now();
                }
                if (Schema::hasTable('incentives')) {
                    DB::table('incentives')->insert(array_filter([
                        'user_id' => $userId,
                        'kind' => 'driver_joining',
                        'amount_paise' => $paise,
                        'status' => 'paid',
                        'note' => 'Driver joining bonus credited to wallet',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], fn ($key) => Schema::hasColumn('incentives', $key), ARRAY_FILTER_USE_KEY));
                }
                if ($patch !== []) {
                    $driver->update($patch);
                }
            }
        }
        app(IncentiveService::class)->grantDriverReferralOnSignup($driver->fresh() ?? $driver);
    }

    public function ratings(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $stats = $this->rideRatingAverage((int) $driver->id);
        $ids = \App\Models\Booking::query()->where('driver_id', $driver->id)->pluck('id');
        $list = [];
        if ($ids->isNotEmpty() && Schema::hasTable('booking_ratings')) {
            $q = DB::table('booking_ratings')->whereIn('booking_id', $ids);
            if (Schema::hasColumn('booking_ratings', 'from_role')) {
                $q->where('from_role', 'CUSTOMER');
            }
            $list = $q->orderByDesc('id')->limit(40)->get()->map(function ($row) {
                $booking = \App\Models\Booking::query()->find($row->booking_id);

                return [
                    'id' => (string) $row->id,
                    'stars' => (int) ($row->stars ?? 0),
                    'comment' => $row->comment ?? null,
                    'publicRef' => $booking?->public_ref,
                    'pickupText' => $booking?->pickup_text,
                    'dropText' => $booking?->drop_text,
                    'createdAt' => $row->created_at,
                ];
            })->all();
        }

        return [
            'average' => $stats['average'],
            'count' => $stats['count'],
            'ratings' => $list,
        ];
    }

    /**
     * @return array{average: float, count: int}
     */
    private function rideRatingAverage(int $driverId): array
    {
        $ids = \App\Models\Booking::query()->where('driver_id', $driverId)->pluck('id');
        if ($ids->isEmpty() || ! Schema::hasTable('booking_ratings')) {
            return ['average' => 0.0, 'count' => 0];
        }
        $q = DB::table('booking_ratings')->whereIn('booking_id', $ids);
        if (Schema::hasColumn('booking_ratings', 'from_role')) {
            $q->where('from_role', 'CUSTOMER');
        }
        $count = (int) (clone $q)->count();
        $avg = $count > 0 ? round((float) (clone $q)->avg('stars'), 2) : 0.0;
        if (Schema::hasColumn('drivers', 'rating_avg')) {
            Driver::query()->where('id', $driverId)->update(['rating_avg' => $avg]);
        }

        return ['average' => $avg, 'count' => $count];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduledOffers(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->first();
        if (! $driver) {
            return [];
        }
        $category = $driver->vehicles->first()?->category;
        $rows = \App\Models\Booking::query()
            ->whereNotNull('scheduled_at')
            ->whereNotIn('product', ['LOCAL_CAB', 'CITY_RIDE', 'CITY'])
            ->whereNotIn('status', \App\Support\BookingStatus::terminal())
            ->whereNull('driver_id')
            ->whereIn('status', array_merge(\App\Support\BookingStatus::searching(), ['CONFIRMED']))
            ->when($category, function ($query) use ($category) {
                $family = \App\Support\VehicleCategory::family((string) $category);
                $query->where(function ($cat) use ($family) {
                    $cat->whereIn('category', $family)->orWhereNull('category');
                });
            })
            ->orderBy('scheduled_at')
            ->limit(40)
            ->get();

        return $rows->map(function ($row) use ($actor, $driver) {
            $presented = app(BookingService::class)->present($row, $actor);
            $presented['kind'] = 'ride';
            $presented['scheduled'] = true;
            $presented['actions'] = ['accept', 'reject'];
            $blocked = app(BookingService::class)->scheduleBlockReason($driver, $row);
            if ($blocked !== null) {
                $shown = app(BookingService::class)->presentedProduct($row);
                if (in_array($shown, ['ONE_WAY', 'ROUND_WAY', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'PARCEL', 'RENTAL'], true)) {
                    $presented['acceptBlocked'] = $blocked;
                    $presented['actions'] = ['reject'];
                } else {
                    return null;
                }
            }

            return $presented;
        })->filter()->values()->all();
    }

    private function upsertVehicle(Driver $driver, array $data): void
    {
        $category = strtoupper((string) ($data['category'] ?? $data['vehicleCategory'] ?? ''));
        $reg = strtoupper(preg_replace('/\s+/', '', (string) ($data['registrationNo'] ?? $data['registration_no'] ?? '')) ?? '');
        if ($category === '' && $reg === '') {
            return;
        }
        $driver->loadMissing('user');
        $districtId = $driver->user?->district_id
            ?: DB::table('districts')->orderBy('id')->value('id');
        abort_unless($districtId, 422, 'Select your city before adding a vehicle');
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->first();
        $payload = array_filter([
            'district_id' => $districtId,
            'driver_id' => $driver->id,
            'category' => $category ?: ($vehicle->category ?? 'BIKE'),
            'registration_no' => $reg ?: ($vehicle->registration_no ?? null),
            'status' => 'ACTIVE',
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'color' => $data['color'] ?? null,
            'fuel' => isset($data['fuel']) ? strtoupper((string) $data['fuel']) : null,
            'updated_at' => now(),
        ], fn ($v) => $v !== null && $v !== '');
        abort_unless(! empty($payload['registration_no']), 422, 'Enter the vehicle registration number');
        $family = strtoupper((string) ($data['vehicleFamily'] ?? $data['vehicle_family'] ?? ''));
        if ($family !== '' && Schema::hasColumn('drivers', 'vehicle_family')) {
            $driver->update(['vehicle_family' => $family]);
        }
        if ($vehicle) {
            $vehicle->update($payload);
        } else {
            $payload['created_at'] = now();
            Vehicle::query()->create($payload);
        }
    }

    private function identityTaken(string $kind, string $value, int $ignoreUserId): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if ($kind === 'email') {
            return User::query()->whereRaw('LOWER(email) = ?', [strtolower($value)])->where('id', '!=', $ignoreUserId)->exists();
        }
        if ($kind === 'phone') {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if (strlen($digits) === 10) {
                $digits = '91'.$digits;
            }

            return User::query()->where('phone', $digits)->where('id', '!=', $ignoreUserId)->exists();
        }
        $q = Driver::query()->where('user_id', '!=', $ignoreUserId);
        if ($kind === 'aadhaar') {
            return $q->where('aadhaar_hash', $value)->exists();
        }
        if ($kind === 'pan') {
            return $q->where('pan_hash', $value)->exists();
        }
        if ($kind === 'license') {
            return $q->whereRaw('UPPER(license_no) = ?', [strtoupper($value)])
                ->whereNotIn('license_no', ['PENDING', 'NA', ''])
                ->exists();
        }

        return false;
    }
}
