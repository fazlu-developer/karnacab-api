<?php

namespace App\Services;

use App\Mail\EventNoticeMail;
use App\Mail\TripInvoiceMail;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use App\Support\BookingProductSchema;
use App\Support\FareCommissionSplit;
use App\Support\FleetDhShare;
use App\Support\BookingStatus;
use App\Support\Geo;
use App\Support\IndiaTime;
use App\Support\ServiceArea;
use App\Support\ServiceMaintenance;
use App\Support\TripFareDetail;
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
        $dto['product'] = $this->coerceStoredProduct($dto);
        BookingProductSchema::ensure();
        $pickupLat = isset($dto['pickupLat']) ? (float) $dto['pickupLat'] : null;
        $pickupLng = isset($dto['pickupLng']) ? (float) $dto['pickupLng'] : null;
        $dropLat = isset($dto['dropLat']) ? (float) $dto['dropLat'] : null;
        $dropLng = isset($dto['dropLng']) ? (float) $dto['dropLng'] : null;
        abort_unless($pickupLat !== null && $pickupLng !== null, 422, 'Pickup coordinates are required');
        abort_unless($dropLat !== null && $dropLng !== null, 422, 'Drop coordinates are required');
        $areaInput = $dto + [
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
            'dropLat' => $dropLat,
            'dropLng' => $dropLng,
            'pickupText' => $dto['pickupText'] ?? $dto['pickup_text'] ?? null,
            'dropText' => $dto['dropText'] ?? $dto['drop_text'] ?? null,
        ];
        ServiceArea::assertTrip($areaInput);
        ServiceMaintenance::assertOpen((string) ($dto['product'] ?? 'LOCAL_CAB'), $areaInput);

        $resolved = $this->fares->withResolvedTripDistance($dto + [
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
            'dropLat' => $dropLat,
            'dropLng' => $dropLng,
        ]);
        $distanceKm = (float) ($resolved['distanceKm'] ?? $dto['distanceKm'] ?? Geo::haversineKm($pickupLat, $pickupLng, $dropLat, $dropLng));
        $dto['distanceKm'] = $distanceKm;
        $requestedProduct = strtoupper((string) ($dto['product'] ?? 'LOCAL_CAB'));
        if (in_array($requestedProduct, ['ONE_WAY', 'AIRPORT', 'RAILWAY', 'MULTI_STOP'], true)) {
            $dto['product'] = $requestedProduct;
            $dto['lockProduct'] = true;
        } else {
            $dto['product'] = FareService::cityOrOutstation($requestedProduct, $distanceKm);
        }
        $districtId = $this->resolveDistrictId($dto + [
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
        ], $actor);
        $quote = $this->fares->quote([
            'product' => $dto['product'],
            'category' => $dto['category'],
            'distanceKm' => $distanceKm,
            'districtId' => $districtId,
            'hours' => $dto['hours'] ?? null,
            'night' => $dto['night'] ?? false,
            'stopCount' => isset($dto['stops']) ? count($dto['stops']) : ($dto['stopCount'] ?? 0),
            'roundTrip' => $dto['roundTrip'] ?? true,
            'waitMinutes' => $dto['waitMinutes'] ?? 0,
            'tollPaise' => $dto['tollPaise'] ?? 0,
            'parkingPaise' => $dto['parkingPaise'] ?? 0,
            'pickupLat' => $pickupLat,
            'pickupLng' => $pickupLng,
            'lockProduct' => ! empty($dto['lockProduct']) || in_array(strtoupper((string) ($dto['product'] ?? '')), ['LOCAL_CAB', 'CITY_RIDE', 'CITY', 'ONE_WAY', 'AIRPORT', 'RAILWAY', 'MULTI_STOP'], true),
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
        if ($this->settings->firstRideFreeEnabled()) {
            $prior = Booking::query()
                ->where('customer_id', $actor->id)
                ->whereIn('status', [BookingStatus::COMPLETED, 'COMPLETED'])
                ->count();
            if ($prior === 0) {
                $quote['discountPaise'] = (int) ($quote['totalPaise'] ?? 0);
                $quote['totalPaise'] = 0;
                $quote['firstRideFree'] = true;
            }
        } else {
            $promo = app(IncentiveService::class)->quotePromoDiscount((int) $actor->id, (int) ($quote['totalPaise'] ?? 0));
            if ($promo > 0) {
                $quote['promoCreditPaise'] = $promo;
                $quote['discountPaise'] = (int) ($quote['discountPaise'] ?? 0) + $promo;
                $quote['totalPaise'] = max(0, (int) ($quote['totalPaise'] ?? 0) - $promo);
            }
        }

        $radius = $this->settings->radiusKm();
        $product = strtoupper((string) ($dto['product'] ?? 'LOCAL_CAB'));
        $timeout = self::searchesNearbyNow($product) ? 600 : $this->settings->requestTimeoutSeconds();
        $scheduledAt = null;
        $keepFlightTime = in_array($product, ['AIRPORT', 'RAILWAY'], true);
        $keepChosenTime = $keepFlightTime || in_array($product, ['ONE_WAY', 'ROUND_WAY', 'SCHEDULE', 'LOCAL_CAB', 'RENTAL', 'MULTI_STOP', 'PARCEL'], true);
        if (! empty($dto['scheduledAt']) && (! self::searchesNearbyNow($product) || $keepChosenTime)) {
            try {
                $scheduledAt = \Illuminate\Support\Carbon::parse($dto['scheduledAt']);
            } catch (\Throwable) {
                $scheduledAt = null;
            }
        }
        if (self::searchesNearbyNow($product) && ! $keepChosenTime) {
            $dto['scheduledAt'] = null;
        } elseif ($scheduledAt !== null) {
            $dto['scheduledAt'] = $scheduledAt->toDateTimeString();
        }
        if ($this->isCityProduct($product)) {
            $product = 'LOCAL_CAB';
            $dto['product'] = 'LOCAL_CAB';
            $cityFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(5));
            if (! $cityFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $quote['cityMode'] = $cityFuture ? 'schedule' : 'now';
            $quote['advanceAfterAccept'] = false;
            $quote['prebook'] = false;
            $quote['advancePercent'] = 0;
            $quote['advancePaise'] = 0;
        }
        if ($product === 'ONE_WAY') {
            $oneWayFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $oneWayFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $oneWayFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $oneWayPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['oneWayMode'] = $oneWayFuture ? 'schedule' : 'now';
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($oneWayPaise * 0.10);
            $quote['commissionSettled'] = false;
        }
        if ($product === 'AIRPORT') {
            $airportFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $airportFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $airportFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $lockedPaise = (int) ($dto['quotePaise'] ?? 0);
            if ($lockedPaise > 0) {
                $quote['totalPaise'] = $lockedPaise;
                $quote['totalRupees'] = $lockedPaise / 100;
            }
            $direction = strtolower((string) ($dto['airportDirection'] ?? $dto['direction'] ?? 'to'));
            if (! in_array($direction, ['to', 'from'], true)) {
                $direction = 'to';
            }
            $airportPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['airportMode'] = $airportFuture ? 'schedule' : 'now';
            $quote['airportDirection'] = $direction;
            $quote['airportName'] = trim((string) ($dto['airportName'] ?? ''));
            $quote['terminal'] = trim((string) ($dto['terminal'] ?? ''));
            $quote['flightNo'] = trim((string) ($dto['flightNumber'] ?? $dto['flightNo'] ?? ''));
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($airportPaise * 0.10);
            $quote['commissionSettled'] = false;
        }
        if ($product === 'RAILWAY') {
            $railwayFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $railwayFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $railwayFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $lockedPaise = (int) ($dto['quotePaise'] ?? 0);
            if ($lockedPaise > 0) {
                $quote['totalPaise'] = $lockedPaise;
                $quote['totalRupees'] = $lockedPaise / 100;
            }
            $direction = strtolower((string) ($dto['railwayDirection'] ?? $dto['direction'] ?? 'to'));
            if (! in_array($direction, ['to', 'from'], true)) {
                $direction = 'to';
            }
            $railwayPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['railwayMode'] = $railwayFuture ? 'schedule' : 'now';
            $quote['railwayDirection'] = $direction;
            $quote['stationName'] = trim((string) ($dto['stationName'] ?? ''));
            $quote['trainNo'] = trim((string) ($dto['trainNumber'] ?? $dto['trainNo'] ?? ''));
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($railwayPaise * 0.10);
            $quote['commissionSettled'] = false;
        }
        if ($product === 'ROUND_WAY') {
            $roundFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $roundFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $roundFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $roundPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['roundTrip'] = true;
            $quote['roundTripMode'] = $roundFuture ? 'schedule' : 'now';
            $quote['roundTripPhase'] = null;
            $quote['baseRoundTripPaise'] = $roundPaise;
            $quote['waitingRate'] = (int) ($quote['waitingPaisePerMin'] ?? 0);
            $quote['waitingPaisePerMin'] = (int) ($quote['waitingPaisePerMin'] ?? 0);
            $quote['waitingMinutes'] = 0;
            $quote['waitingChargePaise'] = 0;
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($roundPaise * 0.10);
            $quote['commissionSettled'] = false;
        }
        if ($product === 'PARCEL') {
            $parcelFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $parcelFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $parcelFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $lockedPaise = (int) ($dto['quotePaise'] ?? 0);
            if ($lockedPaise > 0) {
                $quote['totalPaise'] = $lockedPaise;
                $quote['totalRupees'] = $lockedPaise / 100;
                $quote['source'] = 'parcel';
            }
            $parcelPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['parcelMode'] = $parcelFuture ? 'schedule' : 'now';
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($parcelPaise * 0.10);
            $quote['commissionSettled'] = false;
            $quote['parcelType'] = $dto['parcelType'] ?? null;
            $quote['receiverName'] = $dto['passengerName'] ?? $dto['contactName'] ?? null;
            $quote['receiverPhone'] = $dto['passengerPhone'] ?? $dto['contactPhone'] ?? null;
            $quote['packageNotes'] = $dto['instructions'] ?? null;
            $quote['weightKg'] = $dto['weightKg'] ?? null;
        }
        if ($product === 'RENTAL') {
            if ($scheduledAt === null || ! $scheduledAt->greaterThan(now()->addMinutes(15))) {
                abort(422, 'Cab rental is always scheduled. Choose a pickup time at least 15 minutes from now.');
            }
            $draft = app(RentalService::class)->stampQuote($dto, $quote);
            $requote = $this->fares->quote([
                'product' => 'RENTAL',
                'lockProduct' => true,
                'category' => $dto['category'] ?? $quote['category'] ?? 'SEDAN',
                'distanceKm' => $distanceKm,
                'districtId' => $districtId,
                'hours' => $draft['hours'],
                'night' => $dto['night'] ?? false,
                'stopCount' => count($draft['stops'] ?? []),
                'roundTrip' => false,
                'waitMinutes' => 0,
                'pickupLat' => $pickupLat,
                'pickupLng' => $pickupLng,
            ]);
            $quote = app(RentalService::class)->stampQuote(array_merge($dto, [
                'rentalMode' => $draft['rentalMode'],
                'hours' => $draft['hours'],
                'rentalHours' => $draft['rentalHours'],
                'rentalDays' => $draft['rentalDays'],
                'stops' => $draft['stops'],
            ]), $requote);
            $dto['hours'] = $quote['hours'];
            $dto['stops'] = $quote['stops'];
            $dto['product'] = 'RENTAL';
            $dto['lockProduct'] = true;
            $product = 'RENTAL';
        }
        if ($product === 'MULTI_STOP') {
            $multiStops = $this->preparedMultiStops(is_array($dto['stops'] ?? null) ? $dto['stops'] : [], $pickupLat, $pickupLng);
            if (count($multiStops) < 2) {
                abort(422, 'Add at least two stops after pickup.');
            }
            $multiFuture = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(15));
            if ($scheduledAt !== null && ! $multiFuture) {
                abort(422, 'Choose a pickup time at least 15 minutes from now.');
            }
            if (! $multiFuture) {
                $scheduledAt = null;
                $dto['scheduledAt'] = null;
            }
            $lastStop = $multiStops[count($multiStops) - 1];
            $dropLat = (float) $lastStop['lat'];
            $dropLng = (float) $lastStop['lng'];
            $dto['dropLat'] = $dropLat;
            $dto['dropLng'] = $dropLng;
            $dto['dropText'] = $lastStop['text'];
            $via = array_slice($multiStops, 0, -1);
            $route = $this->fares->directions($pickupLat, $pickupLng, $dropLat, $dropLng, $via);
            $routedKm = (float) ($route['distanceKm'] ?? $distanceKm);
            $shownKm = (float) ($dto['clientDistanceKm'] ?? 0);
            if ($shownKm > 0) {
                $distanceKm = $shownKm;
            } else {
                $distanceKm = $routedKm;
            }
            $dto['distanceKm'] = $distanceKm;
            $multiStops = $this->scaleStopLegs($multiStops, $distanceKm);
            $lockedPaise = (int) ($dto['quotePaise'] ?? 0);
            if ($lockedPaise > 0) {
                $quote['totalPaise'] = $lockedPaise;
                $quote['totalRupees'] = $lockedPaise / 100;
            }
            $multiPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['multiStopMode'] = $multiFuture ? 'schedule' : 'now';
            $quote['multiStops'] = $multiStops;
            $quote['stops'] = $multiStops;
            $quote['currentStopIndex'] = 0;
            $quote['totalKm'] = $distanceKm;
            $quote['billedKm'] = $distanceKm;
            $quote['stopCount'] = count($multiStops);
            $quote['routePolyline'] = $route['polyline'] ?? ($resolved['polyline'] ?? null);
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($multiPaise * 0.10);
            $quote['commissionSettled'] = false;
            $quote['roundTrip'] = false;
            $quote['waitMinutes'] = 0;
            $dto['stops'] = $multiStops;
            $dto['stopCount'] = count($multiStops);
            $dto['waitMinutes'] = 0;
            $dto['roundTrip'] = false;
        }
        $advanceProducts = array_keys(\App\Support\AdvancePayment::PRODUCTS);
        $futureSchedule = $scheduledAt !== null && $scheduledAt->greaterThan(now()->addMinutes(5)) && in_array($product, $advanceProducts, true);
        $driverDispatch = self::driverCanAccept($product);
        if ($futureSchedule) {
            $timeout = max($timeout, $scheduledAt->getTimestamp() - now()->getTimestamp());
        }
        $status = $driverDispatch ? BookingStatus::SEARCHING : 'CONFIRMED';

        $matches = [];
        $notifyCitySchedule = $this->isCityProduct($product) && $futureSchedule;
        $notifyOneWay = $product === 'ONE_WAY';
        $notifyRound = $product === 'ROUND_WAY';
        $notifyParcel = $product === 'PARCEL';
        $notifyAirport = $product === 'AIRPORT';
        $notifyRailway = $product === 'RAILWAY';
        $notifyMultiStop = $product === 'MULTI_STOP';
        $notifyRental = $product === 'RENTAL';
        if ($driverDispatch && (! $futureSchedule || $notifyCitySchedule || $notifyOneWay || $notifyRound || $notifyParcel || $notifyAirport || $notifyRailway || $notifyMultiStop || $notifyRental)) {
            $matches = $this->nearby->search(
                $pickupLat,
                $pickupLng,
                (string) $dto['category'],
                $radius,
                false,
                $districtId,
                (string) ($dto['product'] ?? 'LOCAL_CAB'),
            );
        }
        // One-way and round-trip nearby searches pay the full fare after the trip.
        // A future schedule collects 10% only after a driver accepts.
        $collectAdvance = \App\Support\AdvancePayment::enabled($product) && ! $futureSchedule;
        if (\App\Support\AdvancePayment::payAfterCompleteUnlessScheduled($product)) {
            $collectAdvance = false;
        }
        if ($collectAdvance) {
            $totalPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['prebook'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($totalPaise * 0.10);
            if ((int) $quote['advancePaise'] < 1) {
                $collectAdvance = false;
                $quote['prebook'] = false;
                $quote['advancePercent'] = 0;
                $quote['advancePaise'] = 0;
            }
        }
        $mode = $this->normalizePaymentMode($dto['paymentMode'] ?? $dto['payment_mode'] ?? 'CASH');
        $laterAdvance = $futureSchedule && \App\Support\AdvancePayment::enabled($product) && ! $this->isCityProduct($product);
        if ($laterAdvance && $mode === 'CASH') {
            $mode = 'UPI';
        }
        if ($collectAdvance && $mode === 'CASH') {
            abort(422, 'Cash cannot confirm this booking. Pay the 10% advance with PayU or wallet.');
        }
        $payuTxn = trim((string) ($dto['payuTxnId'] ?? $dto['payu_txn_id'] ?? ''));
        if ($collectAdvance && $mode === 'UPI') {
            abort_unless($payuTxn !== '', 422, 'Pay the 10% advance with PayU to confirm this booking.');
            $intent = DB::table('payment_intents')->where('txnid', $payuTxn)->where('user_id', $actor->id)->first();
            abort_unless($intent && ($intent->status ?? '') === 'captured', 422, 'PayU advance is not paid yet.');
            abort_unless((int) $intent->amount_paise >= (int) ($quote['advancePaise'] ?? 0), 422, 'PayU advance is less than 10%.');
            $quote['advancePaidPaise'] = (int) ($quote['advancePaise'] ?? 0);
            $quote['payuTxnId'] = $payuTxn;
            $quote['commissionSettled'] = true;
        }
        if ($mode === 'WALLET' && $collectAdvance) {
            $balance = app(CustomerWallet::class)->balancePaise((int) $actor->id);
            abort_unless($balance >= (int) ($quote['advancePaise'] ?? 0), 422, 'Add wallet balance to pay the 10% advance.');
        }

        $booking = Booking::query()->create($this->filterColumns([
            'public_ref' => $this->nextPublicRef(),
            'customer_id' => $actor->id,
            'district_id' => $districtId,
            'product' => $dto['product'],
            'category' => $dto['category'],
            'status' => $status,
            'search_expires_at' => $futureSchedule ? $scheduledAt : ($driverDispatch ? now()->addSeconds($timeout) : null),
            'pickup_text' => $dto['pickupText'] ?? $dto['pickup'] ?? 'Pickup',
            'drop_text' => $dto['dropText'] ?? $dto['drop'] ?? 'Drop',
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'drop_lat' => $dropLat,
            'drop_lng' => $dropLng,
            'distance_km' => $quote['billedKm'] ?? $distanceKm,
            'quote_paise' => $quote['totalPaise'],
            'quote_snapshot' => array_merge($quote, [
                'realProduct' => (string) $dto['product'],
                'serviceType' => \App\Support\TripFareDetail::serviceLabel((string) $dto['product']),
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
        $this->ensureParcelShipment($booking, $dto);
        if ($futureSchedule && \App\Support\AdvancePayment::enabled($product) && ! $this->isCityProduct($product)) {
            $totalPaise = (int) ($quote['totalPaise'] ?? 0);
            $quote['prebook'] = true;
            $quote['advanceAfterAccept'] = true;
            $quote['advancePercent'] = 10;
            $quote['advancePaise'] = (int) round($totalPaise * 0.10);
            $booking->update($this->filterColumns([
                'quote_snapshot' => array_merge(is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [], $quote),
            ]));
        }
        if ($collectAdvance && $mode === 'WALLET' && (int) ($quote['advancePaise'] ?? 0) > 0) {
            app(CustomerWallet::class)->debit((int) $actor->id, (int) $quote['advancePaise'], '10% advance for booking '.$booking->public_ref.' paid to admin', (int) $booking->id, 'advance');
            $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
            $snapshot['advancePaidPaise'] = (int) $quote['advancePaise'];
            $snapshot['commissionSettled'] = true;
            $quote['advancePaidPaise'] = (int) $quote['advancePaise'];
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }
        if ($collectAdvance && (int) ($quote['advancePaidPaise'] ?? 0) > 0) {
            $fresh = $booking->fresh();
            $this->settleAdvanceCommission($fresh, (int) $quote['advancePaise']);
            if ($mode !== 'WALLET') {
                app(CustomerWallet::class)->recordMemo(
                    (int) $actor->id,
                    (int) $fresh->id,
                    (int) $quote['advancePaise'],
                    '10% advance for booking '.$fresh->public_ref.' paid to admin',
                    'advance',
                );
            }
        }

        Log::info('booking.created', [
            'bookingId' => $booking->id,
            'product' => $booking->product,
            'status' => $booking->status,
            'nearby' => count($matches),
            'offeredDriverIds' => array_map(fn ($row) => (int) ($row['driverId'] ?? 0), $matches),
            'radiusKm' => $radius,
        ]);
        if ($product === 'PARCEL' && $matches === []) {
            Log::warning('parcel.offer_none', [
                'bookingId' => $booking->id,
                'category' => $booking->category,
                'pickupLat' => $pickupLat,
                'pickupLng' => $pickupLng,
                'radiusKm' => $radius,
                'scheduledAt' => $booking->scheduled_at,
            ]);
        }

        $presented = $this->present($booking->fresh(), $actor);
        $opsTitle = $driverDispatch
            ? 'New ride request'
            : 'Assign a driver · '.str_replace('_', ' ', $product);
        try {
            if ($driverDispatch && $matches !== []) {
                $this->writeOffers($booking, $matches);
                $this->push->notifyUsers(
                    array_map(fn ($row) => (int) ($row['userId'] ?? 0), $matches),
                    $product === 'PARCEL' ? 'New parcel request' : ($product === 'AIRPORT' ? 'New airport ride' : ($product === 'RAILWAY' ? 'New railway ride' : 'New KarnaRide booking')),
                    trim(($booking->pickup_text ?: 'Pickup').' → '.($booking->drop_text ?: 'Drop')),
                    [
                        'type' => 'booking_offer',
                        'event' => 'new_booking',
                        'product' => (string) $booking->product,
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

        $rows = $q->limit(100)->get()->map(fn ($row) => $this->present($row, $actor))->values()->all();
        if (in_array($actor->role, ['CUSTOMER', 'CORPORATE'], true)) {
            try {
                $rows = array_merge($rows, app(\App\Services\Tour\TourBookingService::class)->customerCards($actor));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('tour.list_failed', ['error' => $e->getMessage()]);
            }
            try {
                $rows = array_merge($rows, app(\App\Services\Bulk\BulkBookingService::class)->customerCards($actor));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('bulk.list_failed', ['error' => $e->getMessage()]);
            }
            try {
                $rows = array_merge($rows, app(\App\Services\Corporate\CorporatePlanService::class)->customerCards($actor));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('corporate.list_failed', ['error' => $e->getMessage()]);
            }
        }

        return $rows;
    }

    public function one(User $actor, string $id): array
    {
        $this->expireSearching();

        return $this->present($this->findVisible($actor, $id), $actor);
    }

    public function live(User $actor, string $id): array
    {
        $booking = $this->findVisible($actor, $id);
        if (in_array(strtoupper((string) $booking->product), ['TRAVEL', 'TOUR'], true)) {
            $presented = $this->present($booking, $actor);
            $presented['tracking'] = false;
            $presented['driverLat'] = null;
            $presented['driverLng'] = null;
            $presented['location'] = null;

            return $presented;
        }
        $presented = $this->present($booking, $actor);
        $driverUser = null;
        if ($booking->driver_id) {
            $driver = Driver::query()->with('user')->find($booking->driver_id);
            $driverUser = $driver?->user;
        }

        $location = $driverUser && $driverUser->last_lat !== null ? [
            'lat' => (float) $driverUser->last_lat,
            'lng' => (float) $driverUser->last_lng,
            'heading' => $driverUser->last_heading ?? null,
            'recordedAt' => optional($driverUser->location_updated_at)?->toIso8601String(),
            'stale' => $driverUser->location_updated_at
                ? $driverUser->location_updated_at->lt(now()->subSeconds($this->settings->locationStaleSeconds()))
                : true,
        ] : null;
        $onTrip = in_array($booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::OTP_VERIFIED, BookingStatus::LEGACY_STARTED, 'ONGOING'], true);
        $roundPhase = (string) ($presented['roundTripPhase'] ?? '');
        if ($roundPhase === 'return') {
            $targetLat = $booking->pickup_lat;
            $targetLng = $booking->pickup_lng;
        } elseif ($roundPhase === 'outbound' || $roundPhase === 'waiting' || $onTrip) {
            $targetLat = $booking->drop_lat;
            $targetLng = $booking->drop_lng;
        } else {
            $targetLat = $booking->pickup_lat;
            $targetLng = $booking->pickup_lng;
        }
        $rentalTrack = null;
        if ($this->isRental($booking)) {
            $rentalTrack = app(RentalService::class)->track($booking);
            if ((string) $booking->status === BookingStatus::RENTAL_IN_USE && $rentalTrack['lat'] !== null) {
                $location = [
                    'lat' => (float) $rentalTrack['lat'],
                    'lng' => (float) $rentalTrack['lng'],
                    'heading' => null,
                    'recordedAt' => $rentalTrack['recordedAt'],
                    'stale' => (bool) $rentalTrack['stale'],
                    'source' => 'customer_rental_gps',
                ];
                $targetLat = $booking->pickup_lat;
                $targetLng = $booking->pickup_lng;
            }
        }
        $remainingKm = null;
        $etaMinutes = null;
        if ($location && $targetLat !== null && $targetLng !== null) {
            $remainingKm = Geo::haversineKm((float) $location['lat'], (float) $location['lng'], (float) $targetLat, (float) $targetLng);
            $etaMinutes = max(1, (int) ceil(($remainingKm / 25) * 60));
        }

        return [
            'booking' => $presented,
            'driver' => $presented['driver'] ?? null,
            'location' => $location,
            'target' => $targetLat !== null && $targetLng !== null ? [
                'lat' => (float) $targetLat,
                'lng' => (float) $targetLng,
                'kind' => $onTrip ? 'drop' : 'pickup',
            ] : null,
            'remainingKm' => $remainingKm,
            'etaMinutes' => $etaMinutes,
            'rental' => $rentalTrack,
        ];
    }

    public function rentalLocation(User $actor, string $id, float $lat, float $lng): array
    {
        abort_unless($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180, 422, 'That location is not valid.');
        $booking = $this->findVisible($actor, $id);
        abort_unless($this->isRental($booking), 422, 'This booking is not a cab rental.');
        abort_unless((string) $booking->status === BookingStatus::RENTAL_IN_USE, 422, 'Cab location starts after handover.');
        abort_unless($actor->role === 'CUSTOMER' && (int) $actor->id === (int) $booking->customer_id, 403, 'While the cab is in use, the customer app sends its location so the driver can watch the car.');
        $this->ensureTripKmLogs();
        $prev = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderByDesc('id')->first();
        $prevLat = $prev ? (float) $prev->lat : (float) $booking->pickup_lat;
        $prevLng = $prev ? (float) $prev->lng : (float) $booking->pickup_lng;
        $delta = Geo::haversineKm($prevLat, $prevLng, $lat, $lng);
        $tracked = (float) ($booking->actual_distance_km ?? 0);
        $counted = 0.0;
        if ($delta >= 0.01 && $delta <= 8) {
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
        if (Schema::hasColumn('bookings', 'actual_distance_km')) {
            $booking->update(['actual_distance_km' => round(max(0, $tracked), 3)]);
        }

        return $this->live($actor, $id);
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
        $this->assertScheduleSlotFree($driver, $preview);
        $vehicles = $driver->vehicles;
        if ($vehicles->isEmpty()) {
            $vehicles = \App\Models\Vehicle::query()->where('driver_id', $driver->id)->get();
        }
        $wanted = strtoupper((string) $preview->category);
        $vehicle = $vehicles->first(fn ($row) => \App\Support\VehicleCategory::sameFamily((string) $row->category, $wanted));
        abort_unless($vehicle, 422, 'This booking needs a matching '.$wanted.' vehicle.');
        $wallet = Schema::hasTable('wallets')
            ? DB::table('wallets')->where('owner_user_id', $actor->id)->where(function ($q) {
                if (Schema::hasColumn('wallets', 'owner_type')) {
                    $q->where('owner_type', 'DRIVER');
                }
            })->orderBy('id')->first()
            : null;
        if (! $this->scheduleSkipsDriverWallet($preview)) {
            $eligibility = $this->settings->walletEligibility(
                (int) ($wallet->balance_paise ?? 0),
                (int) ($preview->quote_paise ?? 0),
                (int) ($wallet->joining_credit_paise ?? 0),
                (int) ($wallet->frozen_paise ?? 0),
            );
            abort_unless($eligibility['eligible'], 422, $eligibility['reason'] ?? 'Wallet balance is too low to accept this booking.');
        }

        $assigned = false;
        DB::transaction(function () use ($id, $driver, $vehicle, &$assigned) {
            $booking = Booking::query()->where('id', $id)->lockForUpdate()->first();
            abort_unless($booking, 404, 'Booking not found');
            abort_unless(BookingStatus::isSearching((string) $booking->status) && ! $booking->driver_id, 409, 'Booking has already been accepted by another driver.');
            abort_unless(\App\Support\VehicleCategory::sameFamily((string) $vehicle->category, (string) $booking->category), 422, 'Vehicle category does not match this booking.');
            $future = $booking->scheduled_at && \Illuminate\Support\Carbon::parse($booking->scheduled_at)->greaterThan(now()->addMinutes(5));

            $updated = Booking::query()
                ->where('id', $booking->id)
                ->whereNull('driver_id')
                ->whereIn('status', BookingStatus::searching())
                ->update($this->filterColumns([
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'status' => $future ? 'SCHEDULED' : BookingStatus::DRIVER_ACCEPTED,
                ]));
            abort_unless($updated === 1, 409, 'Booking has already been accepted by another driver.');
            if (! $future) {
                $driver->update(['duty_status' => 'on_trip']);
            }
            $this->markRequest((int) $booking->id, (int) $driver->id, 'ACCEPTED');
            $this->closeOtherOffers((int) $booking->id, (int) $driver->id);
            $assigned = true;
        });

        abort_unless($assigned, 409, 'Booking has already been accepted by another driver.');
        $booking = Booking::query()->findOrFail($id);
        $this->syncLinkedParcel($booking, 'assigned');
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
                $snap = is_array($noticeBooking->quote_snapshot) ? $noticeBooking->quote_snapshot : [];
                $futureAccept = $noticeBooking->scheduled_at
                    && \Illuminate\Support\Carbon::parse($noticeBooking->scheduled_at)->greaterThan(now()->addMinutes(5));
                if ($this->isCityRide($noticeBooking) && $futureAccept) {
                    $title = 'City ride scheduled successfully';
                    $body = 'Your driver accepted the city ride. Track it in the app. Share the start OTP when the driver activates.';
                } elseif ($this->isOneWay($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your one way. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your one way. Your driver heads to pickup after payment.';
                } elseif ($this->isAirport($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your airport ride. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your airport ride. Your driver heads to pickup after payment.';
                } elseif ($this->isRailway($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your railway ride. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your railway ride. Your driver heads to pickup after payment.';
                } elseif ($this->isParcel($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your parcel. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your parcel. Pickup starts after payment.';
                } elseif ($this->isMultiStop($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your multi-stop. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your multi-stop. Your driver heads to pickup after payment.';
                } elseif ($this->isRoundTrip($noticeBooking)) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = $futureAccept
                        ? 'Pay 10% to confirm your round trip. It stays scheduled after payment.'
                        : 'Pay 10% to confirm your round trip. Your driver heads to pickup after payment.';
                } elseif ($futureAccept && ! empty($snap['advanceAfterAccept']) && (int) ($snap['advancePaidPaise'] ?? 0) < 1) {
                    $title = 'Driver accepted. Pay 10% to confirm';
                    $body = 'Pay the 10% commission with PayU or wallet to confirm booking '.$noticeBooking->public_ref.'.';
                }
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

    public static function searchesNearbyNow(string $product): bool
    {
        return in_array(strtoupper($product), ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'PARCEL', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'SCHEDULE', 'RENTAL'], true);
    }

    public static function driverCanAccept(string $product): bool
    {
        return self::searchesNearbyNow($product);
    }

    public function cancel(User $actor, string $id, array $data = []): array
    {
        return $this->lifecycle($actor, $id, 'cancel', null, $data);
    }

    public function rate(User $actor, string $id, int $stars, string $comment): array
    {
        $booking = $this->findVisible($actor, $id);
        $stars = max(1, min(5, $stars));
        if (Schema::hasTable('booking_ratings')) {
            $columns = Schema::getColumnListing('booking_ratings');
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
            if (in_array('from_role', $columns, true)) {
                $match['from_role'] = $actor->role;
            }
            DB::table('booking_ratings')->updateOrInsert(
                $match,
                array_filter($row, fn ($key) => in_array($key, $columns, true), ARRAY_FILTER_USE_KEY),
            );
        }
        if ($booking->driver_id && Schema::hasTable('booking_ratings')) {
            $ids = Booking::query()->where('driver_id', $booking->driver_id)->pluck('id');
            $avg = $ids->isEmpty() ? null : DB::table('booking_ratings')->whereIn('booking_id', $ids)->avg('stars');
            if ($avg !== null) {
                Driver::query()->where('id', $booking->driver_id)->update(['rating_avg' => round((float) $avg, 2)]);
            }
        }

        return $this->present($booking->fresh(), $actor) + ['ok' => true];
    }

    public function verifyOtp(User $actor, string $id, string $otp): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only the assigned driver can verify OTP');
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($booking->status === BookingStatus::DRIVER_ARRIVED, 422, 'Arrive at pickup before verifying OTP.');
        abort_unless(hash_equals((string) $booking->start_otp, trim($otp)), 422, 'Invalid OTP');
        if ($this->isRental($booking)) {
            abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::RENTAL_IN_USE), 422, 'Invalid booking status');
            $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
            $snapshot['rentalPhase'] = 'in_use';
            $snapshot['assignedAt'] = now()->toIso8601String();
            $snapshot['baseRentalPaise'] = (int) ($snapshot['baseRentalPaise'] ?? $booking->quote_paise);
            $booking->update($this->filterColumns([
                'status' => BookingStatus::RENTAL_IN_USE,
                'otp_verified_at' => now(),
                'trip_started_at' => now(),
                'quote_snapshot' => $snapshot,
            ]));
            $this->push->notifyUsers(
                [(int) $booking->customer_id],
                'Cab assigned',
                'Handover OTP confirmed. The cab is with you. Open Track my cab.',
                ['type' => 'trip', 'event' => 'rental_in_use', 'bookingId' => (string) $booking->id],
            );
            Log::info('rental.assigned', ['bookingId' => $booking->id]);

            return $this->present($booking->fresh(), $actor);
        }
        abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::OTP_VERIFIED), 422, 'Invalid booking status');
        $booking->update($this->filterColumns([
            'status' => BookingStatus::OTP_VERIFIED,
            'otp_verified_at' => now(),
        ]));
        $this->syncLinkedParcel($booking, 'picked_up');
        $this->push->notifyUsers(
            [(int) $booking->customer_id],
            $this->isParcel($booking) ? 'Parcel picked up' : 'OTP confirmed',
            $this->isParcel($booking)
                ? 'Pickup OTP confirmed. Your parcel is in transit.'
                : 'Your driver confirmed the start OTP. The trip will start next.',
            ['type' => 'trip', 'event' => 'otp_verified', 'bookingId' => (string) $booking->id],
        );
        Log::info('booking.otp_verified', ['bookingId' => $booking->id]);
        $fresh = $booking->fresh();
        if (! $this->isScheduledTrip($fresh)) {
            try {
                return $this->startTrip($actor, $id);
            } catch (\Throwable $e) {
                Log::warning('booking.autostart_failed', ['bookingId' => $booking->id, 'error' => $e->getMessage()]);

                return $this->present($fresh, $actor);
            }
        }

        return $this->present($fresh, $actor);
    }

    public function startTrip(User $actor, string $id): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only the assigned driver can start the trip');
        $booking = $this->assignedToDriver($actor, $id);
        if ($this->isRental($booking)) {
            abort(422, 'Handover OTP assigns the cab. A rental has no start trip.');
        }
        if (in_array((string) $booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true)) {
            if ($this->isRoundTrip($booking)) {
                $open = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
                if (empty($open['roundTripPhase']) && empty($open['oneSideCompletedAt']) && empty($open['outboundCompletedAt']) && empty($open['returnStartedAt'])) {
                    $open['roundTripPhase'] = 'outbound';
                    $booking->update($this->filterColumns(['quote_snapshot' => $open]));
                    $booking = $booking->fresh();
                }
            }

            return $this->present($booking, $actor);
        }
        abort_unless((string) $booking->status === BookingStatus::OTP_VERIFIED, 422, 'Confirm the customer OTP before starting the trip.');
        abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::TRIP_STARTED), 422, 'Invalid booking status');
        $startSnapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        if ($this->isRoundTrip($booking)) {
            $startSnapshot['roundTripPhase'] = 'outbound';
        }
        if ($this->isMultiStop($booking)) {
            $startSnapshot['currentStopIndex'] = 0;
            $startSnapshot['roundTripPhase'] = null;
            $startStops = $this->multiStopRows($booking);
            foreach ($startStops as $i => $stop) {
                if (($stop['status'] ?? '') === '') {
                    $startStops[$i]['status'] = 'pending';
                }
            }
            $startSnapshot['multiStops'] = $startStops;
            $startSnapshot['stops'] = $startStops;
        }
        $booking->update($this->filterColumns([
            'status' => BookingStatus::TRIP_STARTED,
            'trip_started_at' => $booking->trip_started_at ?: now(),
            'quote_snapshot' => $startSnapshot,
        ]));
        $this->syncLinkedParcel($booking->fresh(), 'in_transit');
        $this->push->notifyUsers(
            [(int) $booking->customer_id],
            $this->isParcel($booking) ? 'Parcel in transit' : ($this->isMultiStop($booking) ? 'Going to stop 1' : ($this->isRoundTrip($booking) ? 'Going' : 'Trip started')),
            $this->isParcel($booking)
                ? 'Your parcel is on the way to the receiver.'
                : ($this->isMultiStop($booking)
                ? 'OTP started the trip. The driver is going to stop 1. The trip finishes only at the last stop.'
                : ($this->isRoundTrip($booking)
                ? 'OTP started the first side only. The round trip is not finished at the destination.'
                : 'Your trip has started.')),
            ['type' => 'trip', 'event' => 'started', 'bookingId' => (string) $booking->id],
        );
        Log::info('booking.trip_started', ['bookingId' => $booking->id]);

        return $this->present($booking->fresh(), $actor);
    }

    public function lifecycle(User $actor, string $id, string $status, ?string $otp = null, array $extra = []): array
    {
        $action = strtolower($status);
        $booking = $this->findVisible($actor, $id);

        if (in_array($action, ['cancel', 'cancelled', 'customer_cancelled', 'driver_cancelled'], true)) {
            if ($this->isRental($booking) && (string) $booking->status === BookingStatus::RENTAL_IN_USE) {
                abort(422, 'The cab is already with you. Return it to finish the rental.');
            }
            if ($actor->role !== 'DRIVER' && $this->isRental($booking) && $this->commissionLocksDriver($booking)) {
                abort(422, 'This cab rental is confirmed. It cannot be cancelled after the 10% is paid.');
            }
            if ($this->isParcel($booking) && $this->commissionLocksDriver($booking)) {
                if ($actor->role === 'DRIVER') {
                    abort(403, $this->parcelMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the parcel. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this parcel.');
                }
                abort(422, 'This parcel is confirmed. It cannot be cancelled after the 10% is paid.');
            }
            if ($actor->role === 'DRIVER' && $this->commissionLocksDriver($booking)) {
                if ($this->isOneWay($booking)) {
                    abort(403, $this->oneWayMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this booking.');
                }
                if ($this->isAirport($booking)) {
                    abort(403, $this->airportMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this booking.');
                }
                if ($this->isRailway($booking)) {
                    abort(403, $this->railwayMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this booking.');
                }
                if ($this->isMultiStop($booking)) {
                    abort(403, $this->multiStopMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this booking.');
                }
                if ($this->isRental($booking)) {
                    abort(403, 'The customer already paid the 10% commission. Call the customer and activate the rental. You cannot cancel it.');
                }
                if ($this->isRoundTrip($booking)) {
                    abort(403, $this->roundTripMode($booking) === 'schedule'
                        ? 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.'
                        : 'The customer already paid the 10% commission. You cannot cancel this booking.');
                }
                abort(422, 'The customer already paid the 10% commission. Call the customer and activate the booking. You cannot cancel it.');
            }
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
            $this->syncLinkedParcel($booking, 'cancelled');
            $this->push->notifyUsers(
                $targets,
                'Booking cancelled',
                $fullReason !== '' ? $fullReason : 'This trip was cancelled.',
                ['type' => 'trip', 'event' => 'cancelled', 'bookingId' => (string) $booking->id],
            );
            $this->notifyOps($booking, 'Booking cancelled · '.$booking->public_ref);

            return $this->present($booking->fresh(), $actor);
        }

        if (in_array($action, ['arrive', 'driver_arrived', 'arrived'], true)) {
            $booking = $this->assignedToDriver($actor, $id);
            if (($this->isOneWay($booking) && $this->oneWayNeedsAdvance($booking)) || ($this->isRoundTrip($booking) && $this->roundTripNeedsAdvance($booking)) || ($this->isParcel($booking) && $this->parcelNeedsAdvance($booking)) || ($this->isRailway($booking) && $this->railwayNeedsAdvance($booking)) || ($this->isMultiStop($booking) && $this->multiStopNeedsAdvance($booking)) || ($this->isRental($booking) && $this->rentalNeedsAdvance($booking))) {
                abort(422, 'Wait until the customer pays the 10% commission.');
            }
            abort_unless(BookingStatus::canTransition((string) $booking->status, BookingStatus::DRIVER_ARRIVED), 422, 'Invalid booking status');
            $booking->update($this->filterColumns([
                'status' => BookingStatus::DRIVER_ARRIVED,
                'arrived_at' => now(),
            ]));
            $this->syncLinkedParcel($booking, 'arrived');
            $this->push->notifyUsers(
                [(int) $booking->customer_id],
                'Driver has arrived',
                'Your driver is at the pickup point.',
                ['type' => 'trip', 'event' => 'arrived', 'bookingId' => (string) $booking->id],
            );

            return $this->present($booking->fresh(), $actor);
        }

        if (in_array($action, ['verify_otp', 'otp'], true)) {
            if ($otp) {
                return $this->verifyOtp($actor, $id, $otp);
            }
            abort(422, 'OTP is required to confirm the trip.');
        }

        if (in_array($action, ['handover', 'handover_otp'], true)) {
            if (! $otp) {
                abort(422, 'Enter the handover OTP from the customer.');
            }

            return $this->verifyOtp($actor, $id, $otp);
        }

        if (in_array($action, ['return', 'return_otp'], true)) {
            return $this->complete($actor, $id, $otp, isset($extra['durationSeconds']) ? (int) $extra['durationSeconds'] : null);
        }

        if ($action === 'start') {
            if ($otp && (string) $booking->status === BookingStatus::DRIVER_ARRIVED) {
                $this->verifyOtp($actor, $id, $otp);

                return $this->startTrip($actor, $id);
            }

            return $this->startTrip($actor, $id);
        }

        if ($action === 'one_side_complete') {
            return $this->markOneSideComplete($actor, $id);
        }

        if ($action === 'start_return') {
            return $this->startReturn($actor, $id);
        }

        if ($action === 'arrive_stop') {
            return $this->arriveMultiStop($actor, $id);
        }

        if ($action === 'complete_stop') {
            return $this->completeMultiStop($actor, $id);
        }

        if (in_array($action, ['complete', 'completed'], true)) {
            $result = $this->complete($actor, $id, $otp, isset($extra['durationSeconds']) ? (int) $extra['durationSeconds'] : null);
            app(DriverSessionService::class)->finishPendingLogout($actor);

            return $result;
        }

        abort(422, 'Unsupported booking action');
    }

    public function complete(User $actor, string $id, ?string $otp = null, ?int $durationSeconds = null): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        $rentalReturn = $this->isRental($booking);
        if ($rentalReturn && (string) $booking->status === BookingStatus::COMPLETED) {
            return $this->present($booking, $actor);
        }
        if ($rentalReturn) {
            abort_unless((string) $booking->status === BookingStatus::RENTAL_IN_USE, 422, 'Hand the cab over before the customer can return it.');
            $pin = preg_replace('/\D+/', '', (string) $otp) ?? '';
            abort_unless($pin !== '' && hash_equals((string) $booking->end_otp, $pin), 422, 'Invalid return OTP');
            $booking = app(RentalService::class)->applyOvertime($booking);
        } else {
            abort_unless(in_array($booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING'], true), 422, 'Start the trip before completing it.');
        }
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        abort_unless((int) $booking->driver_id === (int) $driver->id, 403, 'Driver is not assigned to this booking.');
        $multiStopFinish = false;
        if ($this->isMultiStop($booking)) {
            $openStops = array_values(array_filter(
                $this->multiStopRows($booking),
                fn ($stop) => ($stop['status'] ?? '') !== 'done',
            ));
            abort_unless($openStops === [], 422, 'Complete each stop in order. The trip finishes only at the last stop.');
            $multiStopFinish = true;
        }
        if (! $multiStopFinish && ! $rentalReturn) {
            $pin = preg_replace('/\D+/', '', (string) $otp) ?? '';
            abort_unless($pin !== '' && hash_equals((string) $booking->end_otp, $pin), 422, $this->isParcel($booking) ? 'Invalid delivery PIN' : 'Invalid completion PIN');
        }

        $startedAt = $booking->trip_started_at;
        $duration = $startedAt ? max(1, (int) abs($startedAt->diffInSeconds(now()))) : 1;
        if ($durationSeconds !== null && $durationSeconds > $duration) {
            $duration = min($durationSeconds, 86400);
        }
        $trackedKm = $this->drivenKmFromLogs($booking);
        $bookedSnapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $roundComplete = strtoupper((string) $booking->product) === 'ROUND_WAY';
        if ($this->isMultiStop($booking)) {
            $wait = 0;
        } elseif ($roundComplete) {
            abort_unless(! empty($bookedSnapshot['returnStartedAt']), 422, 'Complete the first side, then start the return, before ending the round trip.');
            $wait = (int) ($bookedSnapshot['waitingMinutes'] ?? 0);
        } else {
            $wait = 0;
            if ($booking->arrived_at && $startedAt) {
                $wait = max(0, (int) floor(abs($booking->arrived_at->diffInSeconds($startedAt)) / 60));
            }
            $bookedWait = (int) (($bookedSnapshot['fareInputs']['waitMinutes'] ?? 0));
            $wait = max($wait, $bookedWait);
        }
        if (! $rentalReturn && ! $this->isParcel($booking) && ! $this->isMultiStop($booking)) {
            $this->applyRouteFare($booking, $trackedKm, 0, $wait);
            $booking->refresh();
        }
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $quote = is_array($snapshot['live'] ?? null) ? $snapshot['live'] : $snapshot;
        $totalPaise = (int) ($quote['totalPaise'] ?? $booking->quote_paise);
        $snapshotBeforeFare = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $bookedKm = (float) ($snapshotBeforeFare['bookedDistanceKm'] ?? $snapshotBeforeFare['totalKm'] ?? $booking->distance_km ?? 0);
        $actualKm = $trackedKm > 0.05 ? $trackedKm : max($bookedKm, (float) ($booking->actual_distance_km ?: 0));
        if ($this->isMultiStop($booking) && $bookedKm > 0) {
            $actualKm = $bookedKm;
            $totalPaise = (int) ($booking->quote_paise ?: $totalPaise);
        }
        $advancePaid = (int) ($snapshotBeforeFare['advancePaidPaise'] ?? $snapshot['advancePaidPaise'] ?? 0);
        $commissionSettled = (bool) ($snapshotBeforeFare['commissionSettled'] ?? $snapshot['commissionSettled'] ?? false)
            || ($advancePaid > 0 && (bool) ($snapshotBeforeFare['prebook'] ?? $snapshot['prebook'] ?? false));
        $commissionPercent = (float) (DB::table('commission_rules')->where('active', 1)->orderBy('id')->value('percent') ?? 10);
        $commission = $commissionSettled && $advancePaid > 0
            ? $advancePaid
            : (int) round($totalPaise * ($commissionPercent / 100));
        $vehicleRow = $booking->vehicle_id
            ? DB::table('vehicles')->where('id', $booking->vehicle_id)->first()
            : ($driver->id ? DB::table('vehicles')->where('driver_id', $driver->id)->orderByDesc('id')->first() : null);
        $fleetOwnerId = (int) ($vehicleRow->fleet_owner_id ?? $driver->fleet_owner_id ?? 0);
        if ($fleetOwnerId < 1) {
            $fleetOwnerId = null;
        }
        $share = FleetDhShare::resolve((int) $fleetOwnerId);
        $split = FareCommissionSplit::applyFleetShare(
            $totalPaise,
            $commission,
            $fleetOwnerId ? $share['percent'] : null,
            $fleetOwnerId ? $share['paise'] : null,
            (bool) $fleetOwnerId,
        );
        $fleetShare = $commissionSettled ? 0 : $split['fleetPaise'];
        $platformShare = $commissionSettled
            ? (int) ($snapshotBeforeFare['platformCommissionPaise'] ?? $snapshot['platformCommissionPaise'] ?? $commission)
            : $split['platformPaise'];
        $districtShare = $commissionSettled ? 0 : $split['districtHeadPaise'];
        $earning = max(0, $totalPaise - $commission);

        $booking->update($this->filterColumns([
            'status' => BookingStatus::COMPLETED,
            'trip_ended_at' => now(),
            'actual_distance_km' => $actualKm,
            'duration_seconds' => $duration,
            'wait_minutes' => $wait,
            'final_fare_paise' => $totalPaise,
            'commission_paise' => $commission,
            'platform_commission_paise' => $platformShare,
            'district_head_commission_paise' => $districtShare,
            'fleet_commission_paise' => $fleetShare,
            'driver_earning_paise' => $earning,
            'quote_snapshot' => array_merge($snapshot, [
                'final' => $quote,
                'commissionPercent' => $commissionPercent,
                'commissionPaise' => $commission,
                'driverEarningPaise' => $earning,
                'fleetCommissionPaise' => $fleetShare,
                'platformCommissionPaise' => $platformShare,
                'districtHeadCommissionPaise' => $districtShare,
                'fleetCommissionPercent' => $split['fleetPercent'],
                'durationSeconds' => $duration,
                'waitMinutes' => $wait,
                'waitingMinutes' => $roundComplete ? $wait : ($snapshot['waitingMinutes'] ?? $wait),
                'roundTripPhase' => $roundComplete ? 'completed' : ($snapshot['roundTripPhase'] ?? null),
                'returnCompletedAt' => $roundComplete ? now()->toIso8601String() : ($snapshot['returnCompletedAt'] ?? null),
            ]),
        ]));
        $this->syncLinkedParcel($booking, 'delivered');
        Driver::query()->where('id', $driver->id)->update(['duty_status' => 'online']);
        $payMode = strtoupper((string) ($booking->payment_mode ?? 'CASH'));
        if ($commissionSettled) {
            app(WalletAccountService::class)->recordCashTrip(
                (int) $driver->user_id,
                (int) $booking->id,
                $earning,
                0,
                $earning,
                'Booking '.$booking->public_ref.': customer paid the 10% commission online or from wallet. Nothing was deducted from your wallet. 90% remaining ₹'.number_format($earning / 100, 0).'.',
            );
            app(CustomerWallet::class)->recordMemo(
                (int) $booking->customer_id,
                (int) $booking->id,
                $earning,
                '90% fare for booking '.$booking->public_ref.' sent to the driver',
                'driver_fare',
                'SENT',
            );
        } else {
            $collectNote = 'Cash collected from customer ₹'.number_format($totalPaise / 100, 0);
            if (in_array($payMode, ['CASH', 'COD', 'CASH_ON_DELIVERY'], true)) {
                app(WalletAccountService::class)->recordCashTrip(
                    (int) $driver->user_id,
                    (int) $booking->id,
                    $totalPaise,
                    $commission,
                    $totalPaise,
                    $collectNote,
                );
                $this->debitDriverCommission((int) $driver->user_id, (int) $booking->id, $commission, $totalPaise);
            } else {
                $this->creditDriverWallet((int) $driver->user_id, (int) $booking->id, $totalPaise, $commission, $totalPaise);
                $this->debitDriverCommission((int) $driver->user_id, (int) $booking->id, $commission, $totalPaise);
            }
            if ($platformShare > 0) {
                $this->creditPlatformWallet((int) $booking->id, $platformShare, $commission);
            }
            if ($fleetOwnerId && $fleetShare > 0) {
                $this->creditFleetWallet((int) $fleetOwnerId, (int) $booking->id, $fleetShare, $commission);
            }
            if ($districtShare > 0) {
                $this->creditDistrictWallet($booking->district_id ? (int) $booking->district_id : null, (int) $booking->id, $districtShare, $commission);
            }
            $this->debitCustomerWallet((int) $booking->customer_id, (int) $booking->id, max(0, $totalPaise - $advancePaid), (string) ($booking->payment_mode ?? 'CASH'));
        }
        try {
            app(IncentiveService::class)->onBookingCompleted($booking->fresh());
        } catch (\Throwable $e) {
            Log::warning('booking.incentive_failed', ['bookingId' => (string) $booking->id, 'error' => $e->getMessage()]);
        }
        $fresh = $booking->fresh();
        $invoiceRef = $this->writeInvoice($fresh, $totalPaise);
        $presented = $this->present($fresh, $actor);
        $customerId = (int) $booking->customer_id;
        $driverUserId = (int) $driver->user_id;
        $bookingId = (string) $booking->id;
        $roundDone = $roundComplete;
        $multiDone = $this->isMultiStop($fresh);
        $rentalDone = $this->isRental($fresh);
        app()->terminating(function () use ($fresh, $totalPaise, $invoiceRef, $earning, $customerId, $driverUserId, $bookingId, $roundDone, $multiDone, $rentalDone) {
            try {
                $this->emailInvoice($fresh, $totalPaise, $invoiceRef);
                $this->push->notifyUsers(
                    [$customerId],
                    $rentalDone ? 'Cab returned' : ($multiDone ? 'Multi-stop complete' : ($roundDone ? 'Round trip complete' : 'Trip completed')),
                    $rentalDone ? 'The cab is back. Your invoice is the rental quote plus any extra time.' : ($multiDone ? 'The last stop is done. Your invoice matches the multi-stop quote.' : ($roundDone ? 'You are dropped back. The invoice includes the round trip fare and the stay.' : 'Please rate your ride.')),
                    ['type' => 'trip', 'event' => 'completed', 'bookingId' => $bookingId],
                );
                $this->push->notifyUsers(
                    [$driverUserId],
                    $rentalDone ? 'Cab returned' : ($multiDone ? 'Multi-stop complete' : ($roundDone ? 'Round trip complete' : 'Trip completed')),
                    $rentalDone
                        ? 'The cab is back and free. Invoice ₹'.number_format($totalPaise / 100, 0).'.'
                        : ($multiDone
                        ? 'Last stop is done. Earning ₹'.number_format($earning / 100, 0).'.'
                        : ($roundDone
                        ? 'Return drop is done. Collect the updated total, including the stay. Earning ₹'.number_format($earning / 100, 0).'.'
                        : 'Trip earning ₹'.number_format($earning / 100, 0).'.')),
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
        $commissionPaise = (int) ($row->commission_paise ?? $snapshot['commissionPaise'] ?? round($total * 0.1));
        $storedEarn = isset($row->driver_earning_paise) ? (int) $row->driver_earning_paise : null;
        $earningPaise = ($storedEarn !== null && $storedEarn > 0)
            ? $storedEarn
            : max(0, $total - $commissionPaise);
        $driver = $row->driver_id ? Driver::query()->with('user', 'vehicles')->find($row->driver_id) : null;
        $driverUser = $driver?->user;
        $vehicle = null;
        if (! empty($row->vehicle_id)) {
            $vehicle = \App\Models\Vehicle::query()->find($row->vehicle_id);
        }
        if (! $vehicle && $driver) {
            $wanted = strtoupper((string) $row->category);
            $vehicle = $driver->vehicles?->first(
                fn ($item) => \App\Support\VehicleCategory::sameFamily((string) $item->category, $wanted)
            );
        }
        $bookedCategory = (string) $row->category;
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
            'vehicleType' => $bookedCategory,
            'vehicleNumber' => $vehicle?->registration_no,
            'vehicleBrand' => $vehicle?->brand,
            'vehicleModel' => $vehicle?->model,
        ] : null;

        $pickupKm = null;
        if ($driverUser?->last_lat !== null && $row->pickup_lat !== null) {
            $pickupKm = Geo::haversineKm((float) $driverUser->last_lat, (float) $driverUser->last_lng, (float) $row->pickup_lat, (float) $row->pickup_lng);
        }

        $allowed = $this->allowedActions($row, $actor);
        $lifecycle = BookingStatus::lifecycle((string) $row->status);
        $lifecycleLabel = str_replace('_', ' ', $lifecycle);
        if ($lifecycle === 'pending_review') {
            $lifecycleLabel = 'Awaiting admin review';
        }
        $isRound = $this->isRoundTrip($row);
        $roundPhase = $isRound ? $this->roundTripPhase($row, $snapshot) : null;
        if ($isRound && $roundPhase === 'waiting' && empty($snapshot['returnStartedAt'])) {
            $base = (int) ($snapshot['baseRoundTripPaise'] ?? $row->quote_paise ?? $total);
            $rate = (int) ($snapshot['waitingRate'] ?? $snapshot['waitingPaisePerMin'] ?? 0);
            $liveMinutes = $this->roundTripWaitingMinutes($row);
            $liveCharge = max(0, $liveMinutes) * max(0, $rate);
            $gstExtra = ! empty($snapshot['gstAppliesToFare'])
                ? (int) round($liveCharge * ((float) ($snapshot['gstPercent'] ?? 0)) / 100)
                : 0;
            $total = $base + $liveCharge + $gstExtra;
            $breakdown = is_array($snapshot['breakdown'] ?? null) ? $snapshot['breakdown'] : [];
            $breakdown['waitingPaise'] = $liveCharge;
            $snapshot['breakdown'] = $breakdown;
            $snapshot['waitingMinutes'] = $liveMinutes;
            $snapshot['waitingChargePaise'] = $liveCharge;
            if ($storedEarn === null || $storedEarn < 1) {
                $earningPaise = max(0, $total - $commissionPaise);
            }
        }
        $fareDetail = TripFareDetail::build($row, $snapshot, $total, $commissionPaise, $earningPaise);
        $waitMinutes = (int) ($fareDetail['waitingMinutes'] ?? 0);
        $waitChargeRupees = (float) ($fareDetail['waitingRupees'] ?? 0);
        $waitLabel = null;
        if ($isRound && (! empty($snapshot['oneSideCompletedAt']) || $lifecycle === 'completed' || $waitMinutes > 0)) {
            $waitLabel = $fareDetail['waitingLabel'] ?? $this->waitingLabel($waitMinutes);
        } elseif ($waitMinutes > 0) {
            $waitLabel = $this->waitingLabel($waitMinutes);
        }

        $shownProduct = $this->presentedProduct($row);

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'customerId' => (string) $row->customer_id,
            'product' => $shownProduct,
            'category' => $row->category,
            'status' => $row->status,
            'cancelReason' => $row->cancel_reason ?? null,
            'paymentMode' => $row->payment_mode ?? 'CASH',
            'paymentType' => $this->paymentTypeLabel($row->payment_mode ?? 'CASH'),
            'ratings' => $this->ratingsPayload($row),
            'customerReview' => $this->customerReview($row),
            'lifecycle' => $lifecycle,
            'lifecycleLabel' => $lifecycleLabel,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
            'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
            'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
            'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
            'distanceKm' => $row->distance_km !== null ? (float) $row->distance_km : null,
            'bookedDistanceKm' => isset($snapshot['bookedDistanceKm']) ? (float) $snapshot['bookedDistanceKm'] : ($row->distance_km !== null ? (float) $row->distance_km : null),
            'actualDistanceKm' => ($row->actual_distance_km !== null && (float) $row->actual_distance_km > 0)
                ? (float) $row->actual_distance_km
                : ($row->distance_km !== null ? (float) $row->distance_km : null),
            ...($this->isMultiStop($row) ? $this->multiStopPayload($row) : []),
            'durationSeconds' => $row->duration_seconds ?? null,
            'waitMinutes' => $row->wait_minutes ?? null,
            'quotePaise' => (int) $row->quote_paise,
            'driverId' => $row->driver_id ? (string) $row->driver_id : null,
            'scheduledAt' => optional($row->scheduled_at)?->toIso8601String(),
            'scheduledAtLabel' => IndiaTime::format($row->scheduled_at),
            'cityMode' => $this->isCityRide($row) ? $this->cityMode($row) : null,
            'oneWayMode' => $this->isOneWay($row) ? $this->oneWayMode($row) : null,
            ...($this->isRental($row) ? app(RentalService::class)->presentFields($row, (bool) $isCustomer) : []),
            'airportMode' => $this->isAirport($row) ? $this->airportMode($row) : null,
            'airportDirection' => $this->isAirport($row) ? ($snapshot['airportDirection'] ?? null) : null,
            'airportName' => $this->isAirport($row) && trim((string) ($snapshot['airportName'] ?? '')) !== '' ? $snapshot['airportName'] : null,
            'railwayMode' => $this->isRailway($row) ? $this->railwayMode($row) : null,
            'railwayDirection' => $this->isRailway($row) ? ($snapshot['railwayDirection'] ?? null) : null,
            'stationName' => $this->isRailway($row) && trim((string) ($snapshot['stationName'] ?? '')) !== '' ? $snapshot['stationName'] : null,
            'trainNumber' => $row->train_number ?: ($snapshot['trainNo'] ?? null),
            'flightNumber' => $row->flight_number ?: ($snapshot['flightNo'] ?? null),
            'terminal' => $row->terminal ?: ($snapshot['terminal'] ?? null),
            'roundTripMode' => $isRound ? $this->roundTripMode($row) : null,
            'returnAt' => optional($row->return_at)?->toIso8601String(),
            'returnAtLabel' => IndiaTime::format($row->return_at ?? null),
            'prebook' => (bool) ($snapshot['prebook'] ?? false),
            'advancePercent' => (int) ($snapshot['advancePercent'] ?? 0),
            'advancePaise' => (int) ($snapshot['advancePaise'] ?? 0),
            'advancePaidPaise' => (int) ($snapshot['advancePaidPaise'] ?? 0),
            'advanceAfterAccept' => ! empty($snapshot['advanceAfterAccept']),
            'parcelMode' => $this->isParcel($row) ? $this->parcelMode($row) : null,
            'parcelType' => $this->isParcel($row) ? ($snapshot['parcelType'] ?? null) : null,
            'receiverName' => $this->isParcel($row) ? ($snapshot['receiverName'] ?? $row->passenger_name) : null,
            'receiverPhone' => $this->isParcel($row) ? ($snapshot['receiverPhone'] ?? $row->passenger_phone) : null,
            'packageNotes' => $this->isParcel($row) ? ($snapshot['packageNotes'] ?? $row->instructions) : null,
            'needsAdvance' => $this->oneWayNeedsAdvance($row) || $this->roundTripNeedsAdvance($row) || $this->parcelNeedsAdvance($row) || $this->airportNeedsAdvance($row) || $this->railwayNeedsAdvance($row) || $this->multiStopNeedsAdvance($row) || $this->rentalNeedsAdvance($row) || (
                ! empty($snapshot['advanceAfterAccept'])
                && (int) ($snapshot['advancePaidPaise'] ?? 0) < 1
                && (string) $row->status === 'SCHEDULED'
                && ! empty($row->driver_id)
                && ! $this->isCityRide($row)
                && ! $this->isOneWay($row)
                && ! $this->isRoundTrip($row)
                && ! $this->isAirport($row)
                && ! $this->isRailway($row)
                && ! $this->isMultiStop($row)
            ),
            'canActivate' => $this->scheduleCanActivate($row),
            'canCancel' => in_array('cancel', $allowed, true),
            'commissionSettled' => ! empty($snapshot['commissionSettled']) || (int) ($snapshot['advancePaidPaise'] ?? 0) > 0,
            'hideCommissionTag' => $this->isCityRide($row),
            'schedule' => [
                'canCancel' => in_array('cancel', $allowed, true),
                'canReschedule' => (string) $row->status === 'SCHEDULED' && ($this->isCityRide($row) || ! $this->commissionLocksDriver($row)),
            ],
            'serviceType' => strtoupper((string) $row->product) === 'SCHEDULE'
                ? TripFareDetail::serviceLabel($shownProduct)
                : ($fareDetail['serviceLabel'] ?? TripFareDetail::serviceLabel($shownProduct)),
            'roundTripPhase' => $roundPhase,
            'oneSideCompletedAt' => $snapshot['oneSideCompletedAt'] ?? $snapshot['outboundCompletedAt'] ?? null,
            'outboundCompletedAt' => $snapshot['outboundCompletedAt'] ?? $snapshot['oneSideCompletedAt'] ?? null,
            'returnStartedAt' => $snapshot['returnStartedAt'] ?? null,
            'returnCompletedAt' => $snapshot['returnCompletedAt'] ?? null,
            'baseRoundTripPaise' => (int) ($snapshot['baseRoundTripPaise'] ?? ($isRound ? ($row->quote_paise ?? 0) : 0)),
            'waitingRate' => (int) ($snapshot['waitingRate'] ?? $snapshot['waitingPaisePerMin'] ?? 0),
            'gstPercent' => (float) ($snapshot['gstPercent'] ?? 0),
            'gstAppliesToFare' => ! empty($snapshot['gstAppliesToFare']),
            'liveTotalPaise' => $total,
            'waitingMinutes' => $waitMinutes,
            'waitingLabel' => $waitLabel,
            'waitingPaisePerMin' => (int) ($snapshot['waitingPaisePerMin'] ?? 0),
            'waitingChargePaise' => (int) round($waitChargeRupees * 100),
            'waitingChargeRupees' => $waitChargeRupees,
            'advanceRupees' => ((int) ($snapshot['advancePaise'] ?? 0)) / 100,
            'balanceRupees' => max(0, $total - (int) ($snapshot['advancePaise'] ?? 0)) / 100,
            'searchRadiusKm' => $radius,
            'nearbyDriverCount' => $nearbyCount,
            'expiresAt' => optional($expires)?->toIso8601String(),
            'searchExpiresAt' => optional($expires)?->toIso8601String(),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'arrivedAt' => optional($row->arrived_at)?->toIso8601String(),
            'tripStartedAt' => optional($row->trip_started_at)?->toIso8601String(),
            'tripEndedAt' => optional($row->trip_ended_at)?->toIso8601String(),
            'secondsRemaining' => $secondsLeft,
            'startOtp' => ($isCustomer && (! $this->isParcel($row) || $this->parcelPickupOtpVisible($row))) ? $row->start_otp : null,
            'endOtp' => $isCustomer && ! $this->isMultiStop($row) && (
                ($this->isRental($row) && in_array((string) $row->status, [BookingStatus::RENTAL_IN_USE, BookingStatus::COMPLETED], true))
                || (! $this->isRental($row) && in_array($row->status, [BookingStatus::TRIP_STARTED, BookingStatus::COMPLETED], true) && (! $isRound || $roundPhase === 'return' || $roundPhase === 'completed'))
            ) ? $row->end_otp : null,
            'driverName' => $driverPayload['name'] ?? null,
            'driverLat' => $driverPayload['lat'] ?? null,
            'driverLng' => $driverPayload['lng'] ?? null,
            'driverHeading' => $driverPayload['heading'] ?? null,
            'driverPhone' => $driverPayload['phone'] ?? null,
            'pickupDistanceKm' => $pickupKm,
            'driver' => $driverPayload,
            'vehicle' => [
                'registrationNo' => $vehicle?->registration_no,
                'category' => $bookedCategory,
                'brand' => $vehicle?->brand,
                'model' => $vehicle?->model,
            ],
            'customer' => $this->customerPayload($row, $actor),
            'allowedActions' => $allowed,
            'fare' => [
                'source' => 'server',
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'gstPaise' => $snapshot['breakdown']['gstPaise'] ?? ($snapshot['final']['breakdown']['gstPaise'] ?? null),
                'discountPaise' => $snapshot['breakdown']['discountPaise'] ?? null,
                'commissionPaise' => $commissionPaise,
                'commissionRupees' => $commissionPaise / 100,
                'driverEarningPaise' => $earningPaise,
                'driverEarningRupees' => $earningPaise / 100,
                'currency' => 'INR',
                'breakdown' => $snapshot['final']['breakdown'] ?? $snapshot['breakdown'] ?? null,
                'detail' => $fareDetail,
            ],
            'fareDetail' => $fareDetail,
            'estimatedFareRupees' => $total / 100,
            'collectRupees' => $total / 100,
            'amountType' => $this->paymentTypeLabel($row->payment_mode ?? 'CASH'),
            'commissionRupees' => $commissionPaise / 100,
            'estimatedEarningsRupees' => $earningPaise / 100,
            'driverEarningRupees' => $earningPaise / 100,
            'kind' => 'ride',
            'navigation' => $this->navigationPayload($row),
            'wallet' => $this->scheduleSkipsDriverWallet($row)
                ? [
                    'eligible' => true,
                    'reason' => null,
                    'requiredPaise' => 0,
                    'availablePaise' => 0,
                    'shortfallPaise' => 0,
                    'requiredRupees' => 0,
                    'availableRupees' => 0,
                    'shortfallRupees' => 0,
                    'commissionFromCustomer' => true,
                ]
                : $this->driverWalletGate($actor, $total),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function driverWalletGate(?User $actor, int $farePaise): ?array
    {
        if (! $actor || $actor->role !== 'DRIVER' || ! Schema::hasTable('wallets')) {
            return null;
        }
        $wallet = DB::table('wallets')->where('owner_user_id', $actor->id)->where(function ($q) {
            if (Schema::hasColumn('wallets', 'owner_type')) {
                $q->where('owner_type', 'DRIVER');
            }
        })->orderBy('id')->first();
        $gate = $this->settings->walletEligibility(
            (int) ($wallet->balance_paise ?? 0),
            $farePaise,
            (int) ($wallet->joining_credit_paise ?? 0),
            (int) ($wallet->frozen_paise ?? 0),
        );
        $gate['requiredRupees'] = ((int) $gate['requiredPaise']) / 100;
        $gate['availableRupees'] = ((int) $gate['availablePaise']) / 100;
        $gate['shortfallRupees'] = ((int) $gate['shortfallPaise']) / 100;

        return $gate;
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationPayload(Booking $row): array
    {
        $pickup = $this->mapsUrl($row->pickup_lat, $row->pickup_lng);
        $drop = $this->mapsUrl($row->drop_lat, $row->drop_lng);
        $started = in_array((string) $row->status, [BookingStatus::TRIP_STARTED, BookingStatus::OTP_VERIFIED, BookingStatus::LEGACY_STARTED], true);
        $phase = '';
        if (strtoupper((string) $row->product) === 'ROUND_WAY') {
            $phase = (string) $this->roundTripPhase($row, is_array($row->quote_snapshot) ? $row->quote_snapshot : []);
        }
        $current = $pickup;
        if ($phase === 'return') {
            $current = $pickup;
        } elseif ($phase === 'outbound' || $phase === 'waiting' || $started) {
            $current = $drop;
        }

        return [
            'pickupUrl' => $pickup,
            'dropUrl' => $drop,
            'currentUrl' => $current,
        ];
    }

    private function mapsUrl(mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return 'https://www.google.com/maps/dir/?api=1&destination='.(float) $lat.','.(float) $lng.'&travelmode=driving';
    }

    public function remindUpcoming(): int
    {
        $sent = 0;
        $rows = Booking::query()
            ->where(function ($hasTime) {
                $hasTime->whereNotNull('scheduled_at')->orWhere(function ($returnOnly) {
                    $returnOnly->where('product', 'ROUND_WAY')->whereNotNull('return_at');
                });
            })
            ->where(function ($query) {
                $query->where(function ($soon) {
                    $soon->whereNotNull('scheduled_at')->where('scheduled_at', '>', now())->where('scheduled_at', '<=', now()->addHours(3));
                })->orWhere(function ($cityWindow) {
                    $cityWindow->whereIn('product', ['LOCAL_CAB', 'CITY_RIDE', 'CITY'])
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($oneWayWindow) {
                    $oneWayWindow->where('product', 'ONE_WAY')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($airportWindow) {
                    $airportWindow->where('product', 'AIRPORT')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($railwayWindow) {
                    $railwayWindow->where('product', 'RAILWAY')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($rentalWindow) {
                    $rentalWindow->where('product', 'RENTAL')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($multiStopWindow) {
                    $multiStopWindow->where('product', 'MULTI_STOP')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($parcelWindow) {
                    $parcelWindow->where('product', 'PARCEL')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40))
                        ->where('status', 'SCHEDULED');
                })->orWhere(function ($roundWindow) {
                    $roundWindow->where('product', 'ROUND_WAY')
                        ->where('status', 'SCHEDULED')
                        ->where('scheduled_at', '>', now()->subMinutes(20))
                        ->where('scheduled_at', '<=', now()->addMinutes(40));
                })->orWhere(function ($returnWindow) {
                    $returnWindow->where('product', 'ROUND_WAY')
                        ->whereNotNull('return_at')
                        ->where('return_at', '>', now()->subMinutes(20))
                        ->where('return_at', '<=', now()->addMinutes(40));
                });
            })
            ->whereNotIn('status', BookingStatus::terminal())
            ->orderBy('scheduled_at')
            ->limit(80)
            ->get();
        foreach ($rows as $booking) {
            $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
            if ($this->isCityRide($booking)) {
                $sent += $this->remindCityRide($booking, $snapshot);
                continue;
            }
            if ($this->isOneWay($booking)) {
                $sent += $this->remindOneWay($booking, $snapshot);
                continue;
            }
            if ($this->isAirport($booking)) {
                $sent += $this->remindAirport($booking, $snapshot);
                continue;
            }
            if ($this->isRailway($booking)) {
                $sent += $this->remindRailway($booking, $snapshot);
                continue;
            }
            if ($this->isMultiStop($booking)) {
                $sent += $this->remindMultiStop($booking, $snapshot);
                continue;
            }
            if ($this->isRental($booking)) {
                $sent += $this->remindRental($booking, $snapshot);
                continue;
            }
            if ($this->isParcel($booking)) {
                $sent += $this->remindParcel($booking, $snapshot);
                continue;
            }
            if ($this->isRoundTrip($booking)) {
                $sent += $this->remindRoundTrip($booking, $snapshot);
                continue;
            }
            if (! $this->isCityRide($booking) && ! empty($snapshot['advanceAfterAccept']) && (int) ($snapshot['advancePaidPaise'] ?? 0) < 1) {
                continue;
            }
            if (! $booking->driver_id) {
                continue;
            }
            $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the scheduled time';
            $ids = [(int) $booking->customer_id];
            $driverUserId = $booking->driver_id
                ? Driver::query()->where('id', $booking->driver_id)->value('user_id')
                : null;
            if ($driverUserId) {
                $ids[] = (int) $driverUserId;
            }
            $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
            $mins = now()->diffInMinutes($dueAt, false);
            $changed = false;
            if ($mins <= 10 && empty($snapshot['reminder10m'])) {
                $this->push->notifyUsers(
                    $ids,
                    'Ride starts in 10 minutes',
                    'Booking '.$booking->public_ref.' is at '.$when.'. Be at pickup now.',
                    ['type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id],
                );
                if ($driverUserId) {
                    $this->push->notifyUsers(
                        [(int) $driverUserId],
                        'Activate this booking',
                        'Booking '.$booking->public_ref.' starts in 10 minutes. Open the app and activate now.',
                        [
                            'type' => 'booking_offer',
                            'event' => 'activate_reminder',
                            'bookingId' => (string) $booking->id,
                        ],
                    );
                }
                $this->notifyOps($booking, '10 min reminder · '.$booking->public_ref);
                $snapshot['reminder10m'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            } elseif ($mins <= 45 && empty($snapshot['reminder45m'])) {
                $this->push->notifyUsers(
                    [(int) $booking->customer_id],
                    'Ride is almost due',
                    'Booking '.$booking->public_ref.' is at '.$when.'. Be ready at pickup.',
                    ['type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id],
                );
                if ($driverUserId) {
                    $this->push->notifyUsers(
                        [(int) $driverUserId],
                        'Activate this booking',
                        'Booking '.$booking->public_ref.' is at '.$when.'. Open the app and activate now.',
                        [
                            'type' => 'booking_offer',
                            'event' => 'activate_reminder',
                            'bookingId' => (string) $booking->id,
                        ],
                    );
                }
                $this->notifyOps($booking, 'Activate reminder · '.$booking->public_ref);
                $snapshot['reminder45m'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            } elseif (empty($snapshot['reminder3h'])) {
                $this->push->notifyUsers($ids, 'Upcoming ride', 'Booking '.$booking->public_ref.' is at '.$when.'. Be ready about 3 hours before pickup.', [
                    'type' => 'trip',
                    'event' => 'ride_reminder',
                    'bookingId' => (string) $booking->id,
                ]);
                $this->notifyOps($booking, 'Upcoming ride reminder · '.$booking->public_ref);
                $snapshot['reminder3h'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            }
            if ($changed) {
                $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remindRental(Booking $booking, array $snapshot): int
    {
        if (! $booking->scheduled_at || (string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'Cab rental pickup time', 'Your cab rental pickup is here. Open Track my cab and share the handover OTP when your driver arrives.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Cab rental pickup time', 'Pickup time is here. Activate the rental, drive to the customer, then enter the handover OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'Cab rental in 10 minutes', 'Your cab on rent at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Cab rental in 10 minutes', 'Your cab rental is in about 10 minutes. Call the customer, then activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'Cab rental in 30 minutes', 'Your cab on rent at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Cab rental in 30 minutes', 'Your cab rental is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remindCityRide(Booking $booking, array $snapshot): int
    {
        if ($this->cityMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED') {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = $booking->driver_id
            ? (int) Driver::query()->where('id', $booking->driver_id)->value('user_id')
            : 0;
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'City ride pickup time', 'Your city ride pickup is here. Open Track and share the start OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'City ride pickup time', 'Pickup time is here. Activate the city ride, then ask the customer for the start OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'City ride in 10 minutes', 'Your city ride at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'City ride in 10 minutes', 'Your scheduled city ride is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'City ride in 30 minutes', 'Your city ride at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'City ride in 30 minutes', 'Your scheduled city ride is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remindOneWay(Booking $booking, array $snapshot): int
    {
        if ($this->oneWayMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'One way pickup time', 'Your one way pickup is here. Open Track and share the start OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'One way pickup time', 'Pickup time is here. Activate the one way, then ask the customer for the start OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'One way in 10 minutes', 'Your one way at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'One way in 10 minutes', 'Your scheduled one way is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'One way in 30 minutes', 'Your one way at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'One way in 30 minutes', 'Your scheduled one way is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remindAirport(Booking $booking, array $snapshot): int
    {
        if ($this->airportMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'Airport pickup time', 'Your airport pickup is here. Open Track and share the start OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Airport pickup time', 'Pickup time is here. Activate the airport ride, then ask the customer for the start OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'Airport ride in 10 minutes', 'Your airport ride at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Airport ride in 10 minutes', 'Your scheduled airport ride is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'Airport ride in 30 minutes', 'Your airport ride at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Airport ride in 30 minutes', 'Your scheduled airport ride is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function remindRailway(Booking $booking, array $snapshot): int
    {
        if ($this->railwayMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'Railway pickup time', 'Your railway pickup is here. Open Track and share the start OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Railway pickup time', 'Pickup time is here. Activate the railway ride, then ask the customer for the start OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'Railway ride in 10 minutes', 'Your railway ride at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Railway ride in 10 minutes', 'Your scheduled railway ride is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'Railway ride in 30 minutes', 'Your railway ride at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Railway ride in 30 minutes', 'Your scheduled railway ride is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    /**
     * Pickup reminders for a scheduled multi-stop. Book now has no reminder.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function remindMultiStop(Booking $booking, array $snapshot): int
    {
        if ($this->multiStopMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'Multi-stop pickup time', 'Your multi-stop pickup is here. Open Track and share the start OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Multi-stop pickup time', 'Pickup time is here. Activate the multi-stop, then ask the customer for the start OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'Multi-stop in 10 minutes', 'Your multi-stop at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Multi-stop in 10 minutes', 'Your scheduled multi-stop is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'Multi-stop in 30 minutes', 'Your multi-stop at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Multi-stop in 30 minutes', 'Your scheduled multi-stop is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    private function remindParcel(Booking $booking, array $snapshot): int
    {
        if ($this->parcelMode($booking) !== 'schedule' || ! $booking->scheduled_at) {
            return 0;
        }
        if ((string) $booking->status !== 'SCHEDULED' || ! $booking->driver_id) {
            return 0;
        }
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return 0;
        }
        $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
        $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
        $dueAt = \Illuminate\Support\Carbon::parse($booking->scheduled_at);
        $mins = now()->diffInMinutes($dueAt, false);
        $changed = false;
        $sent = 0;
        $customer = [(int) $booking->customer_id];
        $driver = $driverUserId > 0 ? [$driverUserId] : [];
        if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
            $this->push->notifyUsers($customer, 'Parcel pickup time', 'Your parcel pickup is here. Open Track and share the pickup OTP when your driver activates.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Parcel pickup time', 'Pickup time is here. Activate the parcel, then ask for the pickup OTP.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminderPickup'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
            $this->push->notifyUsers($customer, 'Parcel in 10 minutes', 'Your parcel pickup at '.$when.' starts in about 10 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Parcel in 10 minutes', 'Your scheduled parcel is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder10m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
            $this->push->notifyUsers($customer, 'Parcel in 30 minutes', 'Your parcel pickup at '.$when.' is in about 30 minutes.', [
                'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
            ]);
            if ($driver !== []) {
                $this->push->notifyUsers($driver, 'Parcel in 30 minutes', 'Your scheduled parcel is in about 30 minutes. Call the customer before you activate.', [
                    'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                ]);
            }
            $snapshot['reminder30m'] = now()->toIso8601String();
            $changed = true;
            $sent++;
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    /**
     * Pickup reminders match one way. A stored return time also reminds before the return window.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function remindRoundTrip(Booking $booking, array $snapshot): int
    {
        $changed = false;
        $sent = 0;
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if ($this->roundTripMode($booking) === 'schedule' && $booking->scheduled_at && (string) $booking->status === 'SCHEDULED' && $booking->driver_id && $paid) {
            $when = \App\Support\IndiaTime::format($booking->scheduled_at) ?: 'the pickup time';
            $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
            $mins = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($booking->scheduled_at), false);
            $customer = [(int) $booking->customer_id];
            $driver = $driverUserId > 0 ? [$driverUserId] : [];
            if ($mins <= 2 && empty($snapshot['reminderPickup'])) {
                $this->push->notifyUsers($customer, 'Round trip pickup time', 'Your round trip pickup is here. Open Track and share the start OTP when your driver activates.', [
                    'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
                ]);
                if ($driver !== []) {
                    $this->push->notifyUsers($driver, 'Round trip pickup time', 'Pickup time is here. Activate the round trip, then ask the customer for the start OTP.', [
                        'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                    ]);
                }
                $snapshot['reminderPickup'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            } elseif ($mins <= 10 && $mins > 2 && empty($snapshot['reminder10m'])) {
                $this->push->notifyUsers($customer, 'Round trip in 10 minutes', 'Your round trip at '.$when.' starts in about 10 minutes.', [
                    'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
                ]);
                if ($driver !== []) {
                    $this->push->notifyUsers($driver, 'Round trip in 10 minutes', 'Your scheduled round trip is in about 10 minutes. Call the customer, then activate when you are both ready.', [
                        'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                    ]);
                }
                $snapshot['reminder10m'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            } elseif ($mins <= 30 && $mins > 10 && empty($snapshot['reminder30m'])) {
                $this->push->notifyUsers($customer, 'Round trip in 30 minutes', 'Your round trip at '.$when.' is in about 30 minutes.', [
                    'type' => 'trip', 'event' => 'ride_reminder', 'bookingId' => (string) $booking->id,
                ]);
                if ($driver !== []) {
                    $this->push->notifyUsers($driver, 'Round trip in 30 minutes', 'Your scheduled round trip is in about 30 minutes. Call the customer before you activate.', [
                        'type' => 'booking_offer', 'event' => 'activate_reminder', 'bookingId' => (string) $booking->id,
                    ]);
                }
                $snapshot['reminder30m'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            }
        }
        if ($paid && $booking->return_at && ! \App\Support\BookingStatus::isTerminal((string) $booking->status)) {
            $returnMins = now()->diffInMinutes(\Illuminate\Support\Carbon::parse($booking->return_at), false);
            $ids = [(int) $booking->customer_id];
            if ($booking->driver_id) {
                $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
                if ($driverUserId > 0) {
                    $ids[] = $driverUserId;
                }
            }
            if ($returnMins <= 30 && $returnMins > 10 && empty($snapshot['reminderReturn30'])) {
                $this->push->notifyUsers($ids, 'Return window in 30 minutes', 'The return side of round trip '.$booking->public_ref.' is in about 30 minutes.', [
                    'type' => 'trip', 'event' => 'return_reminder', 'bookingId' => (string) $booking->id,
                ]);
                $snapshot['reminderReturn30'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            } elseif ($returnMins <= 10 && $returnMins >= -5 && empty($snapshot['reminderReturn10'])) {
                $this->push->notifyUsers($ids, 'Return window is here', 'The return side of round trip '.$booking->public_ref.' is due. Start return when the customer is ready.', [
                    'type' => 'trip', 'event' => 'return_reminder', 'bookingId' => (string) $booking->id,
                ]);
                $snapshot['reminderReturn10'] = now()->toIso8601String();
                $changed = true;
                $sent++;
            }
        }
        if ($changed) {
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        }

        return $sent;
    }

    public function markOneSideComplete(User $actor, string $id): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($this->isRoundTrip($booking), 422, 'One side complete is only for a round trip.');
        abort_unless(in_array((string) $booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true), 422, 'Start going before completing the first side.');
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        if (! empty($snapshot['returnStartedAt']) || ! empty($snapshot['oneSideCompletedAt']) || ! empty($snapshot['outboundCompletedAt'])) {
            if (($snapshot['roundTripPhase'] ?? '') === 'outbound' || empty($snapshot['roundTripPhase'])) {
                $snapshot['roundTripPhase'] = ! empty($snapshot['returnStartedAt']) ? 'return' : 'waiting';
                $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
                $booking = $booking->fresh();
            }

            return $this->present($booking, $actor);
        }
        $rate = (int) ($snapshot['waitingPaisePerMin'] ?? $snapshot['waitingRate'] ?? 0);
        if ($rate < 1) {
            $rate = (int) (DB::table('fare_rules')
                ->where('product', 'ROUND_WAY')
                ->where('category', $booking->category)
                ->where('active', 1)
                ->orderByDesc('district_id')
                ->value('waiting_paise_per_min') ?? 0);
        }
        $now = now()->toIso8601String();
        $snapshot['oneSideCompletedAt'] = $now;
        $snapshot['outboundCompletedAt'] = $now;
        $snapshot['roundTripPhase'] = 'waiting';
        $snapshot['waitingPaisePerMin'] = $rate;
        $snapshot['waitingRate'] = $rate;
        $snapshot['waitingMinutes'] = 0;
        $snapshot['waitingChargePaise'] = 0;
        if (empty($snapshot['baseRoundTripPaise'])) {
            $snapshot['baseRoundTripPaise'] = (int) $booking->quote_paise;
        }
        $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        $this->pushRoundTrip($booking, 'One side complete', 'The first side is complete. The driver will wait, then return. Stay time is added to the fare.', 'one_side_complete');

        return $this->present($booking->fresh(), $actor);
    }

    public function startReturn(User $actor, string $id): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($this->isRoundTrip($booking), 422, 'Return start is only for a round trip.');
        abort_unless(in_array((string) $booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true), 422, 'The round trip is not live.');
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        if (! empty($snapshot['returnStartedAt'])) {
            if (($snapshot['roundTripPhase'] ?? '') !== 'return') {
                $snapshot['roundTripPhase'] = 'return';
                $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
                $booking = $booking->fresh();
            }

            return $this->present($booking, $actor);
        }
        $outboundAt = $snapshot['outboundCompletedAt'] ?? $snapshot['oneSideCompletedAt'] ?? null;
        abort_unless(! empty($outboundAt), 422, 'Complete the first side before starting the return.');
        $minutes = $this->minutesBetween($outboundAt, now()->toIso8601String());
        $now = now()->toIso8601String();
        $snapshot['oneSideCompletedAt'] = $snapshot['oneSideCompletedAt'] ?? $outboundAt;
        $snapshot['outboundCompletedAt'] = $outboundAt;
        $snapshot['returnStartedAt'] = $now;
        $snapshot['roundTripPhase'] = 'return';
        $snapshot['waitingMinutes'] = $minutes;
        $snapshot['waitingLabel'] = $this->waitingLabel($minutes);
        $inputs = is_array($snapshot['fareInputs'] ?? null) ? $snapshot['fareInputs'] : [];
        $inputs['waitMinutes'] = $minutes;
        $inputs['roundTrip'] = true;
        $snapshot['fareInputs'] = $inputs;
        $booking->update($this->filterColumns([
            'quote_snapshot' => $snapshot,
            'wait_minutes' => $minutes,
        ]));
        $fresh = $booking->fresh();
        $this->applyRouteFare($fresh, $this->drivenKmFromLogs($fresh), 0, $minutes);
        $fresh->refresh();
        $priced = is_array($fresh->quote_snapshot) ? $fresh->quote_snapshot : [];
        $live = is_array($priced['live'] ?? null) ? $priced['live'] : [];
        $liveBreakdown = is_array($live['breakdown'] ?? null) ? $live['breakdown'] : [];
        $breakdown = is_array($priced['breakdown'] ?? null) ? $priced['breakdown'] : [];
        $rate = (int) ($priced['waitingPaisePerMin'] ?? $priced['waitingRate'] ?? 0);
        $charge = (int) ($liveBreakdown['waitingPaise'] ?? $breakdown['waitingPaise'] ?? 0);
        if ($charge < 1 && $rate > 0) {
            $charge = $minutes * $rate;
        }
        $priced['waitingChargePaise'] = $charge;
        $priced['waitingMinutes'] = $minutes;
        $priced['waitingRate'] = $rate;
        $priced['waitingPaisePerMin'] = $rate;
        $priced['roundTripPhase'] = 'return';
        $priced['returnStartedAt'] = $priced['returnStartedAt'] ?? $now;
        $priced['outboundCompletedAt'] = $priced['outboundCompletedAt'] ?? $outboundAt;
        $priced['oneSideCompletedAt'] = $priced['oneSideCompletedAt'] ?? $outboundAt;
        $fresh->update($this->filterColumns([
            'quote_snapshot' => $priced,
            'wait_minutes' => $minutes,
        ]));
        $this->pushRoundTrip($fresh, 'Return started', 'Stay '.$this->waitingLabel($minutes).' is added to the fare. No separate waiting payment.', 'return_started');

        return $this->present($fresh->fresh(), $actor);
    }

    private function pushRoundTrip(Booking $booking, string $title, string $body, string $event): void
    {
        $ids = [(int) $booking->customer_id];
        if ($booking->driver_id) {
            $driverUserId = (int) Driver::query()->where('id', $booking->driver_id)->value('user_id');
            if ($driverUserId > 0) {
                $ids[] = $driverUserId;
            }
        }
        $this->push->notifyUsers(array_values(array_unique($ids)), $title, $body, [
            'type' => 'trip',
            'event' => $event,
            'bookingId' => (string) $booking->id,
        ]);
    }

    /**
     * @return list<string>
     */
    private function commissionLocksDriver(Booking $row): bool
    {
        if ($this->isCityRide($row)) {
            return false;
        }
        if ($this->isOneWay($row) || $this->isRoundTrip($row) || $this->isParcel($row) || $this->isAirport($row) || $this->isRailway($row) || $this->isMultiStop($row) || $this->isRental($row)) {
            $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];

            return (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $paid = (int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled']);
        if (! $paid) {
            return false;
        }
        if ((string) $row->status === 'SCHEDULED') {
            return true;
        }

        return $row->scheduled_at !== null
            && \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(5));
    }

    /**
     * @return list<string>
     */
    private function scheduledDriverActions(Booking $row): array
    {
        if ($this->isCityRide($row)) {
            return ['call', 'activate', 'cancel'];
        }
        $actions = ['call'];
        if ($this->scheduleCanActivate($row)) {
            $actions[] = 'activate';
        }
        if ($this->isCityRide($row) || ! $this->commissionLocksDriver($row)) {
            $actions[] = 'cancel';
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function driverEnRouteActions(Booking $row): array
    {
        if (($this->isOneWay($row) && $this->oneWayNeedsAdvance($row)) || ($this->isRoundTrip($row) && $this->roundTripNeedsAdvance($row)) || ($this->isParcel($row) && $this->parcelNeedsAdvance($row)) || ($this->isAirport($row) && $this->airportNeedsAdvance($row)) || ($this->isRailway($row) && $this->railwayNeedsAdvance($row)) || ($this->isMultiStop($row) && $this->multiStopNeedsAdvance($row)) || ($this->isRental($row) && $this->rentalNeedsAdvance($row))) {
            return ['call', 'cancel'];
        }

        if ($this->commissionLocksDriver($row)) {
            return $this->isRental($row) ? ['call', 'arrive'] : ['arrive'];
        }

        return ['arrive', 'cancel'];
    }

    public function scheduleBlockReason(Driver $driver, Booking $incoming): ?string
    {
        if ($this->scheduleConflicts($driver, $incoming)) {
            return 'You have already accepted a booking at this time.';
        }

        return null;
    }

    private function isCityRide(object $row): bool
    {
        return $this->presentedProduct($row) === 'LOCAL_CAB';
    }

    private function isOneWay(object $row): bool
    {
        return $this->presentedProduct($row) === 'ONE_WAY';
    }

    private function isRental(object $row): bool
    {
        return app(RentalService::class)->isRental($row);
    }

    private function rentalNeedsAdvance(Booking $row): bool
    {
        if (! $this->isRental($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function isAirport(object $row): bool
    {
        return $this->presentedProduct($row) === 'AIRPORT';
    }

    private function isRailway(object $row): bool
    {
        return $this->presentedProduct($row) === 'RAILWAY';
    }

    private function isParcel(object $row): bool
    {
        return $this->presentedProduct($row) === 'PARCEL';
    }

    private function parcelMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['parcelMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    private function parcelNeedsAdvance(Booking $row): bool
    {
        if (! $this->isParcel($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function parcelPickupOtpVisible(Booking $row): bool
    {
        if ($this->parcelNeedsAdvance($row)) {
            return false;
        }
        if ($this->parcelMode($row) === 'schedule' && (string) $row->status === 'SCHEDULED') {
            return false;
        }

        return in_array((string) $row->status, [
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
            BookingStatus::DRIVER_ARRIVED,
            BookingStatus::OTP_VERIFIED,
            BookingStatus::TRIP_STARTED,
            BookingStatus::COMPLETED,
            'ONGOING',
            'STARTED',
        ], true);
    }

    private function isRoundTrip(object $row): bool
    {
        return $this->presentedProduct($row) === 'ROUND_WAY';
    }

    private function roundTripMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['roundTripMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function roundTripPhase(Booking $row, array $snapshot): ?string
    {
        if (! $this->isRoundTrip($row)) {
            return null;
        }
        if (! empty($snapshot['returnCompletedAt']) || in_array((string) $row->status, [BookingStatus::COMPLETED, 'COMPLETED'], true)) {
            return 'completed';
        }
        if (! empty($snapshot['returnStartedAt'])) {
            return 'return';
        }
        if (! empty($snapshot['oneSideCompletedAt']) || ! empty($snapshot['outboundCompletedAt'])) {
            return 'waiting';
        }
        if (in_array((string) $row->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true)) {
            return 'outbound';
        }

        return null;
    }

    private function roundTripNeedsAdvance(Booking $row): bool
    {
        if (! $this->isRoundTrip($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function oneWayMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['oneWayMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    private function oneWayNeedsAdvance(Booking $row): bool
    {
        if (! $this->isOneWay($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function airportMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['airportMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    private function airportNeedsAdvance(Booking $row): bool
    {
        if (! $this->isAirport($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function railwayMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['railwayMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    private function railwayNeedsAdvance(Booking $row): bool
    {
        if (! $this->isRailway($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    private function isCityProduct(string $product): bool
    {
        return in_array(strtoupper($product), ['LOCAL_CAB', 'CITY_RIDE', 'CITY'], true);
    }

    /**
     * A stored SCHEDULE row is shown as the service it actually is.
     */
    public function presentedProduct(object $row): string
    {
        $product = strtoupper((string) ($row->product ?? ''));
        if ($product === 'CITY' || $product === 'CITY_RIDE') {
            return 'LOCAL_CAB';
        }
        if ($product !== 'SCHEDULE') {
            return $product === '' ? 'LOCAL_CAB' : $product;
        }

        return $this->productFromScheduleHint($this->snapshotOf($row));
    }

    /**
     * @param  array<string, mixed>  $dto
     */
    private function coerceStoredProduct(array $dto): string
    {
        $product = strtoupper(trim((string) ($dto['product'] ?? 'LOCAL_CAB')));
        if ($product === '') {
            return 'LOCAL_CAB';
        }
        if ($product !== 'SCHEDULE') {
            return $product;
        }

        return $this->productFromScheduleHint([
            'serviceType' => $dto['serviceType'] ?? null,
            'scheduleKind' => $dto['scheduleKind'] ?? null,
            'realProduct' => $dto['realProduct'] ?? ($dto['scheduleService'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $hint
     */
    private function productFromScheduleHint(array $hint): string
    {
        foreach (['realProduct', 'scheduleKind', 'serviceType'] as $key) {
            $mapped = $this->mapProductToken((string) ($hint[$key] ?? ''));
            if ($mapped !== null) {
                return $mapped;
            }
        }
        $label = strtolower(trim((string) ($hint['serviceType'] ?? '')));
        $fromLabel = match (true) {
            str_contains($label, 'one way') || str_contains($label, 'one-way') => 'ONE_WAY',
            str_contains($label, 'round') => 'ROUND_WAY',
            str_contains($label, 'airport') => 'AIRPORT',
            str_contains($label, 'rail') => 'RAILWAY',
            str_contains($label, 'parcel') => 'PARCEL',
            str_contains($label, 'rental') => 'RENTAL',
            str_contains($label, 'multi') => 'MULTI_STOP',
            str_contains($label, 'city') || str_contains($label, 'local') => 'LOCAL_CAB',
            default => null,
        };
        if ($fromLabel !== null) {
            return $fromLabel;
        }
        foreach ([
            'oneWayMode' => 'ONE_WAY',
            'roundTripMode' => 'ROUND_WAY',
            'airportMode' => 'AIRPORT',
            'railwayMode' => 'RAILWAY',
            'parcelMode' => 'PARCEL',
            'multiStopMode' => 'MULTI_STOP',
            'cityMode' => 'LOCAL_CAB',
        ] as $key => $product) {
            if (! empty($hint[$key])) {
                return $product;
            }
        }

        return 'LOCAL_CAB';
    }

    private function mapProductToken(string $token): ?string
    {
        $token = strtoupper(str_replace(['-', ' '], '_', trim($token)));

        return match ($token) {
            'ONE_WAY', 'ONEWAY', 'OUTSTATION' => 'ONE_WAY',
            'ROUND_WAY', 'ROUND', 'ROUND_TRIP', 'ROUNDTRIP' => 'ROUND_WAY',
            'AIRPORT' => 'AIRPORT',
            'RAILWAY', 'RAIL' => 'RAILWAY',
            'PARCEL' => 'PARCEL',
            'RENTAL' => 'RENTAL',
            'MULTI_STOP', 'MULTISTOP', 'MULTI' => 'MULTI_STOP',
            'LOCAL_CAB', 'CITY', 'CITY_RIDE', 'RIDE' => 'LOCAL_CAB',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOf(object $row): array
    {
        $raw = $row->quote_snapshot ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }

    private function isMultiStop(object $row): bool
    {
        return $this->presentedProduct($row) === 'MULTI_STOP';
    }

    private function multiStopMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['multiStopMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan(now()->addMinutes(15))
            ? 'schedule'
            : 'now';
    }

    private function multiStopNeedsAdvance(Booking $row): bool
    {
        if (! $this->isMultiStop($row) || ! $row->driver_id) {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['advanceAfterAccept'])) {
            return false;
        }
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0 || ! empty($snapshot['commissionSettled'])) {
            return false;
        }

        return in_array((string) $row->status, [
            'SCHEDULED',
            BookingStatus::DRIVER_ACCEPTED,
            BookingStatus::LEGACY_ASSIGNED,
            'DRIVER_ARRIVING',
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function preparedMultiStops(array $raw, float $pickupLat, float $pickupLng): array
    {
        $out = [];
        $prevLat = $pickupLat;
        $prevLng = $pickupLng;
        foreach (array_values($raw) as $stop) {
            if (! is_array($stop)) {
                continue;
            }
            $lat = $stop['lat'] ?? $stop['latitude'] ?? null;
            $lng = $stop['lng'] ?? $stop['longitude'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }
            $text = trim((string) ($stop['text'] ?? $stop['address'] ?? $stop['label'] ?? $stop['title'] ?? ''));
            if ($text === '') {
                $text = 'Stop '.(count($out) + 1);
            }
            $leg = Geo::haversineKm($prevLat, $prevLng, (float) $lat, (float) $lng);
            $out[] = [
                'n' => count($out) + 1,
                'text' => $text,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'status' => 'pending',
                'legKm' => round(max($leg, 0), 2),
            ];
            $prevLat = (float) $lat;
            $prevLng = (float) $lng;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>>
     */
    private function scaleStopLegs(array $stops, float $totalKm): array
    {
        $sum = 0.0;
        foreach ($stops as $stop) {
            $sum += (float) ($stop['legKm'] ?? 0);
        }
        if ($sum <= 0 || $totalKm <= 0) {
            return $stops;
        }
        $scale = $totalKm / $sum;
        foreach ($stops as $i => $stop) {
            $stops[$i]['legKm'] = round(((float) ($stop['legKm'] ?? 0)) * $scale, 2);
        }

        return $stops;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function multiStopRows(Booking $row): array
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $stored = $snapshot['multiStops'] ?? $snapshot['stops'] ?? [];
        if (is_array($stored) && $stored !== []) {
            $out = [];
            foreach (array_values($stored) as $index => $stop) {
                if (! is_array($stop)) {
                    continue;
                }
                $lat = $stop['lat'] ?? null;
                $lng = $stop['lng'] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lng)) {
                    continue;
                }
                $status = (string) ($stop['status'] ?? 'pending');
                if (! in_array($status, ['pending', 'arrived', 'done'], true)) {
                    $status = 'pending';
                }
                $out[] = [
                    'n' => (int) ($stop['n'] ?? ($index + 1)),
                    'text' => (string) ($stop['text'] ?? $stop['address'] ?? $stop['label'] ?? ('Stop '.($index + 1))),
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'status' => $status,
                    'legKm' => round((float) ($stop['legKm'] ?? 0), 2),
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }
        if (! Schema::hasTable('booking_stops')) {
            return [];
        }
        $rows = DB::table('booking_stops')->where('booking_id', $row->id)->orderBy('sort_order')->orderBy('id')->get();
        $out = [];
        foreach ($rows as $index => $stop) {
            if ($stop->lat === null || $stop->lng === null) {
                continue;
            }
            $out[] = [
                'n' => $index + 1,
                'text' => (string) ($stop->address ?? $stop->label ?? $stop->title ?? ('Stop '.($index + 1))),
                'lat' => (float) $stop->lat,
                'lng' => (float) $stop->lng,
                'status' => 'pending',
                'legKm' => 0,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function multiStopPayload(Booking $row): array
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $stops = $this->multiStopRows($row);
        $index = max(0, (int) ($snapshot['currentStopIndex'] ?? 0));
        if ($index > count($stops)) {
            $index = count($stops);
        }
        $next = $stops[$index] ?? null;
        $remaining = 0;
        $remainingKm = 0.0;
        for ($i = $index; $i < count($stops); $i++) {
            if (($stops[$i]['status'] ?? '') !== 'done') {
                $remaining++;
                $remainingKm += (float) ($stops[$i]['legKm'] ?? 0);
            }
        }
        $totalKm = (float) ($snapshot['totalKm'] ?? $snapshot['bookedDistanceKm'] ?? $row->distance_km ?? 0);
        $kmToNext = null;
        if ($next && $row->driver_id) {
            $driver = Driver::query()->with('user')->find($row->driver_id);
            $lat = $driver?->user?->last_lat;
            $lng = $driver?->user?->last_lng;
            if ($lat !== null && $lng !== null) {
                $kmToNext = round(Geo::haversineKm((float) $lat, (float) $lng, (float) $next['lat'], (float) $next['lng']), 2);
            }
        }

        return [
            'multiStopMode' => $this->multiStopMode($row),
            'stops' => $stops,
            'stopCount' => count($stops),
            'stopStatuses' => array_map(fn ($stop) => $stop['status'], $stops),
            'currentStopIndex' => $index,
            'nextStop' => $next,
            'remainingStops' => $remaining,
            'totalKm' => $totalKm > 0 ? round($totalKm, 2) : null,
            'remainingKm' => round($remainingKm, 2),
            'kmToNext' => $kmToNext,
            'routePolyline' => $snapshot['routePolyline'] ?? null,
            'distanceKm' => $totalKm > 0 ? round($totalKm, 2) : ($row->distance_km !== null ? (float) $row->distance_km : null),
            'bookedDistanceKm' => $totalKm > 0 ? round($totalKm, 2) : ($row->distance_km !== null ? (float) $row->distance_km : null),
        ];
    }

    /**
     * @return list<string>
     */
    private function multiStopActions(Booking $row): array
    {
        $stops = $this->multiStopRows($row);
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $index = max(0, (int) ($snapshot['currentStopIndex'] ?? 0));
        if (! isset($stops[$index])) {
            return [];
        }
        $status = (string) ($stops[$index]['status'] ?? 'pending');
        if ($status === 'done') {
            return [];
        }

        return $status === 'arrived' ? ['complete_stop'] : ['arrive_stop'];
    }

    public function arriveMultiStop(User $actor, string $id): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($this->isMultiStop($booking), 422, 'This is not a multi-stop trip.');
        abort_unless(in_array((string) $booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true), 422, 'Start the trip before the first stop.');
        $stops = $this->multiStopRows($booking);
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $index = max(0, (int) ($snapshot['currentStopIndex'] ?? 0));
        abort_unless(isset($stops[$index]), 422, 'No stop is left on this trip.');
        $status = (string) ($stops[$index]['status'] ?? 'pending');
        abort_unless($status !== 'done', 422, 'Complete stops in order.');
        if ($status !== 'arrived') {
            $stops[$index]['status'] = 'arrived';
            $snapshot['multiStops'] = $stops;
            $snapshot['stops'] = $stops;
            $snapshot['currentStopIndex'] = $index;
            $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
            $n = (int) ($stops[$index]['n'] ?? ($index + 1));
            $this->push->notifyUsers(
                [(int) $booking->customer_id],
                'Arrived at stop '.$n,
                'Your driver is at stop '.$n.'.',
                ['type' => 'trip', 'event' => 'stop_arrived', 'bookingId' => (string) $booking->id],
            );
        }

        return $this->present($booking->fresh(), $actor);
    }

    public function completeMultiStop(User $actor, string $id): array
    {
        $booking = $this->assignedToDriver($actor, $id);
        abort_unless($this->isMultiStop($booking), 422, 'This is not a multi-stop trip.');
        abort_unless(in_array((string) $booking->status, [BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED'], true), 422, 'Start the trip before completing a stop.');
        $stops = $this->multiStopRows($booking);
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $index = max(0, (int) ($snapshot['currentStopIndex'] ?? 0));
        abort_unless(isset($stops[$index]), 422, 'No stop is left on this trip.');
        abort_unless(($stops[$index]['status'] ?? '') === 'arrived', 422, 'Arrive at this stop before marking it done.');
        $stops[$index]['status'] = 'done';
        $n = (int) ($stops[$index]['n'] ?? ($index + 1));
        $next = $index + 1;
        $snapshot['multiStops'] = $stops;
        $snapshot['stops'] = $stops;
        $snapshot['currentStopIndex'] = $next;
        $booking->update($this->filterColumns(['quote_snapshot' => $snapshot]));
        if ($next >= count($stops)) {
            return $this->complete($actor, $id, null, null);
        }
        $this->push->notifyUsers(
            [(int) $booking->customer_id],
            'Stop '.$n.' done',
            'Stop '.$n.' done, going to stop '.($n + 1).'.',
            ['type' => 'trip', 'event' => 'stop_done', 'bookingId' => (string) $booking->id],
        );

        return $this->present($booking->fresh(), $actor);
    }

    private function cityMode(Booking $row): string
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        $tagged = (string) ($snapshot['cityMode'] ?? '');
        if ($tagged === 'now' || $tagged === 'schedule') {
            return $tagged;
        }
        if (! $row->scheduled_at) {
            return 'now';
        }
        $created = $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at) : now();

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->greaterThan($created->copy()->addMinutes(5))
            ? 'schedule'
            : 'now';
    }

    /**
     * @return list<string>
     */
    private function roundTripActions(Booking $row): array
    {
        if (strtoupper((string) $row->product) !== 'ROUND_WAY') {
            return ['complete'];
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['oneSideCompletedAt'])) {
            return ['one_side_complete'];
        }
        if (empty($snapshot['returnStartedAt'])) {
            return ['start_return'];
        }

        return ['complete'];
    }

    private function roundTripWaitingMinutes(Booking $row): int
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (empty($snapshot['oneSideCompletedAt'])) {
            return (int) ($row->wait_minutes ?? 0);
        }
        $end = $snapshot['returnStartedAt'] ?? now()->toIso8601String();

        return $this->minutesBetween($snapshot['oneSideCompletedAt'], $end);
    }

    private function minutesBetween(mixed $start, mixed $end): int
    {
        try {
            $from = \Illuminate\Support\Carbon::parse($start);
            $to = \Illuminate\Support\Carbon::parse($end);
        } catch (\Throwable) {
            return 0;
        }

        return max(0, (int) floor(abs($from->diffInSeconds($to)) / 60));
    }

    private function waitingLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;
        if ($hours > 0 && $mins > 0) {
            return $hours.' hour'.($hours === 1 ? '' : 's').' '.$mins.' min';
        }
        if ($hours > 0) {
            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return $mins.' min';
    }

    private function scheduleSkipsDriverWallet(Booking $row): bool
    {
        if ($this->isCityRide($row) && $this->isScheduledTrip($row)) {
            return true;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        return ! empty($snapshot['advanceAfterAccept']) || ! empty($snapshot['commissionSettled']);
    }

    private function isScheduledTrip(Booking $row): bool
    {
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (! empty($snapshot['prebook']) || ! empty($snapshot['advanceAfterAccept'])) {
            return true;
        }

        return $row->scheduled_at !== null;
    }

    private function scheduleCanActivate(Booking $row): bool
    {
        if ((string) $row->status !== 'SCHEDULED' || ! $row->scheduled_at) {
            return false;
        }
        if ($this->isOneWay($row) && $this->oneWayMode($row) !== 'schedule') {
            return false;
        }
        if ($this->isAirport($row) && $this->airportMode($row) !== 'schedule') {
            return false;
        }
        if ($this->isRailway($row) && $this->railwayMode($row) !== 'schedule') {
            return false;
        }
        if ($this->isRoundTrip($row) && $this->roundTripMode($row) !== 'schedule') {
            return false;
        }
        if ($this->isParcel($row) && $this->parcelMode($row) !== 'schedule') {
            return false;
        }
        if ($this->isMultiStop($row) && $this->multiStopMode($row) !== 'schedule') {
            return false;
        }
        $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];
        if (! $this->isCityRide($row) && ! empty($snapshot['advanceAfterAccept']) && (int) ($snapshot['advancePaidPaise'] ?? 0) < 1) {
            return false;
        }

        return \Illuminate\Support\Carbon::parse($row->scheduled_at)->lte(now()->addMinutes(45));
    }

    public function payAdvance(User $actor, string $id, array $dto): array
    {
        $booking = Booking::query()->find($id);
        abort_unless($booking, 404, 'Booking not found');
        if ($this->isCityRide($booking)) {
            abort(422, 'A city ride does not take an advance.');
        }
        abort_unless((int) $booking->customer_id === (int) $actor->id, 403, 'This booking is not yours.');
        $status = (string) $booking->status;
        $future = $booking->scheduled_at && \Illuminate\Support\Carbon::parse($booking->scheduled_at)->greaterThan(now()->addMinutes(5));
        abort_unless(in_array($status, ['SCHEDULED', BookingStatus::DRIVER_ACCEPTED, BookingStatus::LEGACY_ASSIGNED], true), 422, 'Wait until a driver accepts this booking.');
        $oneWayPay = $this->isOneWay($booking);
        $roundPay = $this->isRoundTrip($booking);
        $parcelPay = $this->isParcel($booking);
        $airportPay = $this->isAirport($booking);
        $railwayPay = $this->isRailway($booking);
        $multiPay = $this->isMultiStop($booking);
        if ($oneWayPay || $roundPay || $parcelPay || $airportPay || $railwayPay || $multiPay) {
            abort_unless($booking->driver_id, 422, $parcelPay
                ? 'Wait until a driver accepts this parcel.'
                : ($roundPay
                ? 'Wait until a driver accepts this round trip.'
                : ($airportPay
                ? 'Wait until a driver accepts this airport ride.'
                : ($railwayPay
                ? 'Wait until a driver accepts this railway ride.'
                : ($multiPay
                ? 'Wait until a driver accepts this multi-stop ride.'
                : 'Wait until a driver accepts this one way booking.')))));
        } else {
            abort_unless($future || $status === 'SCHEDULED', 422, 'Wait until a driver accepts this scheduled booking.');
        }
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $product = strtoupper((string) $booking->product);
        if (empty($snapshot['advanceAfterAccept']) && $future && \App\Support\AdvancePayment::payAfterCompleteUnlessScheduled($product)) {
            $snapshot['advanceAfterAccept'] = true;
            $snapshot['prebook'] = true;
            $snapshot['advancePercent'] = 10;
            if ((int) ($snapshot['advancePaise'] ?? 0) < 1) {
                $snapshot['advancePaise'] = (int) round(((int) ($booking->quote_paise ?? 0)) * 0.10);
            }
        }
        abort_unless(! empty($snapshot['advanceAfterAccept']), 422, 'This booking does not need an advance.');
        if ((int) ($snapshot['advancePaidPaise'] ?? 0) > 0) {
            return $this->present($booking, $actor);
        }
        $due = (int) ($snapshot['advancePaise'] ?? 0);
        abort_unless($due > 0, 422, 'This booking does not need an advance.');
        $mode = $this->normalizePaymentMode($dto['paymentMode'] ?? $dto['payment_mode'] ?? $booking->payment_mode ?? 'UPI');
        if ($mode === 'CASH') {
            abort(422, 'Pay the 10% advance with PayU or wallet.');
        }
        if ($mode === 'UPI') {
            $payuTxn = trim((string) ($dto['payuTxnId'] ?? $dto['payu_txn_id'] ?? ''));
            abort_unless($payuTxn !== '', 422, 'Pay the 10% advance with PayU to confirm this booking.');
            $intent = DB::table('payment_intents')->where('txnid', $payuTxn)->where('user_id', $actor->id)->first();
            abort_unless($intent && ($intent->status ?? '') === 'captured', 422, 'PayU advance is not paid yet.');
            abort_unless((int) $intent->amount_paise >= $due, 422, 'PayU advance is less than 10%.');
            $snapshot['payuTxnId'] = $payuTxn;
        }
        if ($mode === 'WALLET') {
            $balance = app(CustomerWallet::class)->balancePaise((int) $actor->id);
            abort_unless($balance >= $due, 422, 'Add wallet balance to pay the 10% advance.');
            app(CustomerWallet::class)->debit((int) $actor->id, $due, '10% advance for booking '.$booking->public_ref.' paid to admin', (int) $booking->id, 'advance');
        }
        $snapshot['advancePaidPaise'] = $due;
        $snapshot['commissionSettled'] = true;
        $keepScheduled = $future || $this->isRental($booking) || ($oneWayPay && $this->oneWayMode($booking) === 'schedule') || ($roundPay && $this->roundTripMode($booking) === 'schedule') || ($parcelPay && $this->parcelMode($booking) === 'schedule') || ($airportPay && $this->airportMode($booking) === 'schedule') || ($railwayPay && $this->railwayMode($booking) === 'schedule') || ($multiPay && $this->multiStopMode($booking) === 'schedule');
        $booking->update($this->filterColumns([
            'payment_mode' => $mode,
            'status' => $keepScheduled ? 'SCHEDULED' : (($oneWayPay || $roundPay || $parcelPay || $airportPay || $railwayPay || $multiPay) ? BookingStatus::DRIVER_ACCEPTED : $status),
            'quote_snapshot' => $snapshot,
        ]));
        $fresh = $booking->fresh();
        try {
            $this->settleAdvanceCommission($fresh, $due);
        } catch (\Throwable $e) {
            Log::warning('booking.advance_commission_failed', [
                'bookingId' => $fresh->id,
                'error' => $e->getMessage(),
            ]);
        }
        if ($mode !== 'WALLET') {
            app(CustomerWallet::class)->recordMemo(
                (int) $actor->id,
                (int) $fresh->id,
                $due,
                '10% advance for booking '.$fresh->public_ref.' paid to admin',
                'advance',
            );
        }
        $driverUserId = $fresh->driver_id ? Driver::query()->where('id', $fresh->driver_id)->value('user_id') : null;
        if ($driverUserId) {
            $oneWayNow = $this->isOneWay($fresh) && $this->oneWayMode($fresh) === 'now';
            $roundNow = $this->isRoundTrip($fresh) && $this->roundTripMode($fresh) === 'now';
            $parcelNow = $this->isParcel($fresh) && $this->parcelMode($fresh) === 'now';
            $airportNow = $this->isAirport($fresh) && $this->airportMode($fresh) === 'now';
            $railwayNow = $this->isRailway($fresh) && $this->railwayMode($fresh) === 'now';
            $multiNow = $this->isMultiStop($fresh) && $this->multiStopMode($fresh) === 'now';
            $this->push->notifyUsers(
                [(int) $driverUserId],
                '10% commission paid',
                ($oneWayNow || $roundNow || $parcelNow || $airportNow || $railwayNow || $multiNow)
                    ? 'Customer paid the 10%. Go to pickup. You cannot cancel this booking.'
                    : 'Customer paid the 10% for '.$fresh->public_ref.'. You cannot cancel. Call the customer, then activate when it is time. Commission was not taken from your wallet.',
                ['type' => 'trip', 'event' => 'advance_paid', 'bookingId' => (string) $fresh->id],
            );
        }

        return $this->present($fresh->fresh(), $actor);
    }

    public function activate(User $actor, string $id): array
    {
        abort_unless($actor->role === 'DRIVER', 403, 'Only drivers can start a scheduled booking.');
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $booking = Booking::query()->where('id', $id)->where('driver_id', $driver->id)->first();
        abort_unless($booking, 404, 'Booking not found');
        abort_unless($this->scheduleCanActivate($booking), 422, $this->isCityRide($booking)
            ? 'This scheduled city ride can be activated 45 minutes before pickup.'
            : ($this->isParcel($booking)
                ? 'Activate this parcel only after the 10% is paid, and only near the pickup time.'
                : ($this->isAirport($booking)
                ? 'Activate this airport ride only after the 10% is paid, and only near the pickup time.'
                : ($this->isRailway($booking)
                ? 'Activate this railway ride only after the 10% is paid, and only near the pickup time.'
                : ($this->isOneWay($booking)
                ? 'Activate this one way only after the 10% is paid, and only near the pickup time.'
                : ($this->isRoundTrip($booking)
                    ? 'Activate this round trip only after the 10% is paid, and only near the pickup time.'
                    : 'This scheduled booking can be activated 45 minutes before the pickup time, after the customer pays the 10% advance.'))))));
        $busy = Booking::query()->where('driver_id', $driver->id)->where('id', '!=', $booking->id)->whereIn('status', BookingStatus::openForDriver())->exists();
        abort_if($busy, 422, 'Finish your current trip before activating this booking.');
        $booking->update($this->filterColumns(['status' => BookingStatus::DRIVER_ACCEPTED]));
        $driver->update(['duty_status' => 'on_trip', 'online' => 1]);
        $fresh = $booking->fresh();
        $this->push->notifyUsers(
            [(int) $fresh->customer_id],
            $this->isRental($fresh) ? 'Cab rental is active' : ($this->isCityRide($fresh) ? 'City ride is active' : ($this->isParcel($fresh) ? 'Parcel pickup is active' : ($this->isAirport($fresh) ? 'Airport ride is active' : ($this->isRailway($fresh) ? 'Railway ride is active' : ($this->isOneWay($fresh) ? 'One way is active' : ($this->isMultiStop($fresh) ? 'Multi-stop is active' : ($this->isRoundTrip($fresh) ? 'Round trip is active' : 'Driver is on the way'))))))),
            $this->isRental($fresh)
                ? 'Your driver is bringing the cab. Open Track my cab and share the handover OTP when they arrive.'
                : ($this->isCityRide($fresh)
                ? 'Your driver activated the city ride. Open Track and share the start OTP.'
                : ($this->isParcel($fresh)
                    ? 'Your driver activated the parcel. Open Track and share the pickup OTP.'
                    : ($this->isAirport($fresh)
                    ? 'Your driver activated the airport ride. Open Track and share the start OTP.'
                    : ($this->isRailway($fresh)
                    ? 'Your driver activated the railway ride. Open Track and share the start OTP.'
                    : ($this->isOneWay($fresh)
                    ? 'Your driver activated the one way. Open Track and share the start OTP.'
                    : ($this->isMultiStop($fresh)
                        ? 'Your driver activated the multi-stop. Open Track and share the start OTP. The trip then goes stop by stop.'
                        : ($this->isRoundTrip($fresh)
                        ? 'Your driver activated the round trip. Open Track and share the start OTP. That OTP starts the first side only.'
                        : 'Your scheduled ride is active. The driver is heading to pickup.'))))))),
            ['type' => 'trip', 'event' => 'activated', 'bookingId' => (string) $fresh->id],
        );
        $this->notifyOps($fresh, 'Booking activated · '.$fresh->public_ref);

        return $this->present($fresh, $actor);
    }

    private function allowedActions(Booking $row, ?User $actor): array
    {
        $status = (string) $row->status;
        if (! $actor) {
            return [];
        }
        if ($actor->role === 'CUSTOMER' && (int) $row->customer_id === (int) $actor->id && ! BookingStatus::isTerminal($status) && $status !== BookingStatus::COMPLETED) {
            if (! $this->isOneWay($row) && ! $this->isRoundTrip($row) && ! $this->isAirport($row) && ! $this->isRailway($row) && ! $this->isMultiStop($row) && $this->commissionLocksDriver($row) && ! $this->isCityRide($row) && $this->isScheduledTrip($row)
                && in_array($status, ['SCHEDULED', BookingStatus::DRIVER_ACCEPTED, BookingStatus::LEGACY_ASSIGNED, BookingStatus::DRIVER_ARRIVED, 'DRIVER_ARRIVING'], true)) {
                return [];
            }
            if (in_array($status, [
                BookingStatus::SEARCHING,
                BookingStatus::LEGACY_REQUESTED,
                BookingStatus::PENDING,
                BookingStatus::DRIVER_ACCEPTED,
                BookingStatus::LEGACY_ASSIGNED,
                BookingStatus::DRIVER_ARRIVED,
                'DRIVER_SEARCHING',
                'CONFIRMED',
                'SCHEDULED',
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
                'SCHEDULED' => $this->scheduledDriverActions($row),
                BookingStatus::DRIVER_ACCEPTED, BookingStatus::LEGACY_ASSIGNED, 'DRIVER_ARRIVING' => $this->driverEnRouteActions($row),
                BookingStatus::DRIVER_ARRIVED => $this->isRental($row) ? ['handover'] : ['verify_otp'],
                BookingStatus::OTP_VERIFIED => $this->isRental($row) ? ['handover'] : ['start'],
                BookingStatus::RENTAL_IN_USE => ['return'],
                BookingStatus::TRIP_STARTED, BookingStatus::LEGACY_STARTED, 'ONGOING', 'STARTED' => $this->isRental($row) ? ['return'] : ($this->isMultiStop($row) ? $this->multiStopActions($row) : $this->roundTripActions($row)),
                default => [],
            };
        }

        return [];
    }

    public function paymentTypeLabel(mixed $mode): string
    {
        return match (strtoupper(trim((string) ($mode ?: 'CASH')))) {
            'WALLET', 'WALLET_BALANCE' => 'Wallet',
            'UPI' => 'UPI',
            'PAYU', 'ONLINE', 'CARD', 'NETBANKING', 'RAZORPAY' => 'Online',
            'COD', 'CASH_ON_DELIVERY', 'CASH' => 'Cash',
            default => trim((string) $mode) !== '' ? ucwords(strtolower(str_replace('_', ' ', (string) $mode))) : 'Cash',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function syncLinkedParcel(Booking $booking, string $parcelStatus): void
    {
        if (strtoupper((string) $booking->product) !== 'PARCEL' || ! Schema::hasTable('parcel_shipments')) {
            return;
        }
        if (! Schema::hasColumn('parcel_shipments', 'booking_id')) {
            return;
        }
        DB::table('parcel_shipments')->where('booking_id', $booking->id)->update([
            'driver_id' => $booking->driver_id,
            'status' => $parcelStatus,
            'updated_at' => now(),
        ]);
    }

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
            ->where(function ($query) {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()->addMinutes(5))->orWhereIn('product', ['LOCAL_CAB', 'CITY_RIDE', 'CITY', 'ONE_WAY', 'ROUND_WAY', 'PARCEL', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'RENTAL', 'SCHEDULE']);
            })
            ->whereIn('product', ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'PARCEL', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'SCHEDULE', 'RENTAL'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $pushes = [];
        foreach ($rows as $booking) {
            if (! \App\Support\VehicleCategory::sameFamily($category, (string) $booking->category)) {
                continue;
            }
            if ($this->scheduleConflicts($driver, $booking)) {
                continue;
            }
            $km = Geo::haversineKm($lat, $lng, (float) $booking->pickup_lat, (float) $booking->pickup_lng);
            if ($km > $radius) {
                continue;
            }
            $alreadyOffered = Schema::hasTable('booking_driver_requests')
                && DB::table('booking_driver_requests')
                    ->where('booking_id', $booking->id)
                    ->where('driver_id', $driver->id)
                    ->exists();
            $this->writeOffers($booking, [[
                'driverId' => (int) $driver->id,
                'userId' => (int) $actor->id,
                'distanceKm' => round($km, 2),
            ]]);
            if ($alreadyOffered) {
                continue;
            }
            $pushes[] = [
                'title' => strtoupper((string) $booking->product) === 'PARCEL' ? 'New parcel request' : (strtoupper((string) $booking->product) === 'MULTI_STOP' ? 'New multi-stop ride' : (strtoupper((string) $booking->product) === 'AIRPORT' ? 'New airport ride' : (strtoupper((string) $booking->product) === 'RAILWAY' ? 'New railway ride' : 'New KarnaRide booking'))),
                'body' => trim(($booking->pickup_text ?: 'Pickup').' → '.($booking->drop_text ?: 'Drop')),
                'bookingId' => (string) $booking->id,
                'product' => (string) $booking->product,
            ];
        }
        if ($pushes === []) {
            return;
        }
        $userId = (int) $actor->id;
        foreach ($pushes as $push) {
            $this->push->notifyUsers([$userId], $push['title'], $push['body'], [
                'type' => 'booking_offer',
                'event' => 'new_booking',
                'product' => (string) ($push['product'] ?? ''),
                'bookingId' => $push['bookingId'],
            ]);
        }
    }

    public function offersForDriver(User $actor): array
    {
        $this->expireSearching();
        $driver = Driver::query()->where('user_id', $actor->id)->with('vehicles')->first();
        if (! $driver) {
            return [];
        }
        $ids = $this->offeredBookingIds((int) $driver->id);
        $category = strtoupper((string) ($driver->vehicles->first()?->category ?? ''));
        $driverLat = $actor->last_lat !== null ? (float) $actor->last_lat : null;
        $driverLng = $actor->last_lng !== null ? (float) $actor->last_lng : null;
        $radius = $this->settings->radiusKm();
        $rows = Booking::query()
            ->where('status', BookingStatus::SEARCHING)
            ->whereNull('driver_id')
            ->where(function ($query) {
                $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()->addMinutes(5))->orWhereIn('product', ['LOCAL_CAB', 'CITY_RIDE', 'CITY', 'ONE_WAY', 'ROUND_WAY', 'PARCEL', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'RENTAL', 'SCHEDULE']);
            })
            ->whereIn('product', ['LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'PARCEL', 'AIRPORT', 'RAILWAY', 'SCHEDULE', 'MULTI_STOP', 'RENTAL'])
            ->where(function ($query) use ($ids) {
                if ($ids) {
                    $query->whereIn('id', $ids);
                }
                $query->orWhere('product', 'PARCEL')->orWhere('product', 'MULTI_STOP')->orWhere('product', 'RENTAL');
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->filter(function (Booking $row) use ($ids, $category, $driver, $driverLat, $driverLng, $radius) {
                $visible = $ids && in_array((int) $row->id, $ids, true);
                if (! $visible && $this->isParcel($row)) {
                    $reason = null;
                    $km = null;
                    if ($category === '' || ! \App\Support\VehicleCategory::sameFamily($category, (string) $row->category)) {
                        $reason = 'vehicle_family';
                    } elseif ($driverLat === null || $row->pickup_lat === null) {
                        $reason = 'driver_location_missing';
                    } else {
                        $km = Geo::haversineKm($driverLat, $driverLng ?? 0, (float) $row->pickup_lat, (float) $row->pickup_lng);
                        if ($km > $radius) {
                            $reason = 'radius';
                        }
                    }
                    if ($reason === null) {
                        $visible = true;
                        $this->writeOffers($row, [[
                            'driverId' => (int) $driver->id,
                            'userId' => (int) $driver->user_id,
                            'distanceKm' => round((float) $km, 2),
                        ]]);
                        Log::info('parcel.offer_attached', [
                            'driverId' => (int) $driver->id,
                            'bookingId' => (int) $row->id,
                            'distanceKm' => $km,
                        ]);
                    } elseif (\Illuminate\Support\Facades\Cache::add('parcel-filter:'.$driver->id.':'.$row->id.':'.$reason, 1, now()->addMinutes(2))) {
                        Log::info('parcel.offer_filtered', [
                            'driverId' => (int) $driver->id,
                            'bookingId' => (int) $row->id,
                            'reason' => $reason,
                            'driverCategory' => $category,
                            'bookingCategory' => (string) $row->category,
                            'distanceKm' => $km,
                            'radiusKm' => $radius,
                        ]);
                    }
                }
                if (! $visible && $this->isRental($row)) {
                    $km = null;
                    $eligible = $category !== ''
                        && \App\Support\VehicleCategory::sameFamily($category, (string) $row->category)
                        && $driverLat !== null
                        && $row->pickup_lat !== null;
                    if ($eligible) {
                        $km = Geo::haversineKm($driverLat, $driverLng ?? 0, (float) $row->pickup_lat, (float) $row->pickup_lng);
                        $eligible = $km <= $radius;
                    }
                    if ($eligible) {
                        $visible = true;
                        $this->writeOffers($row, [[
                            'driverId' => (int) $driver->id,
                            'userId' => (int) $driver->user_id,
                            'distanceKm' => round((float) $km, 2),
                        ]]);
                    }
                }
                if (! $visible && $this->isMultiStop($row)) {
                    $km = null;
                    $eligible = $category !== ''
                        && \App\Support\VehicleCategory::sameFamily($category, (string) $row->category)
                        && $driverLat !== null
                        && $row->pickup_lat !== null;
                    if ($eligible) {
                        $km = Geo::haversineKm($driverLat, $driverLng ?? 0, (float) $row->pickup_lat, (float) $row->pickup_lng);
                        $eligible = $km <= $radius;
                    }
                    if ($eligible) {
                        $visible = true;
                        $this->writeOffers($row, [[
                            'driverId' => (int) $driver->id,
                            'userId' => (int) $driver->user_id,
                            'distanceKm' => round((float) $km, 2),
                        ]]);
                    }
                }
                if (! $visible) {
                    return false;
                }
                if ($this->scheduleConflicts($driver, $row) && ! $this->isOneWay($row) && ! $this->isRoundTrip($row) && ! $this->isParcel($row) && ! $this->isAirport($row) && ! $this->isRailway($row) && ! $this->isMultiStop($row) && ! $this->isRental($row)) {
                    return false;
                }

                return true;
            });

        return $rows
            ->map(function (Booking $row) use ($actor, $driver) {
                $presented = $this->present($row, $actor);
                $req = Schema::hasTable('booking_driver_requests')
                    ? DB::table('booking_driver_requests')->where('booking_id', $row->id)->where('driver_id', $driver->id)->first()
                    : null;
                $presented['pickupDistanceKm'] = $req->distance_km ?? $presented['pickupDistanceKm'];
                $presented['actions'] = ['accept', 'reject'];
                $presented['kind'] = 'ride';
                $blocked = $this->scheduleBlockReason($driver, $row);
                if ($blocked !== null && ($this->isOneWay($row) || $this->isRoundTrip($row) || $this->isParcel($row) || $this->isAirport($row) || $this->isRailway($row) || $this->isMultiStop($row) || $this->isRental($row))) {
                    $presented['acceptBlocked'] = $blocked;
                    $presented['actions'] = ['reject'];
                }

                return $presented;
            })
            ->values()
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

    private function assertScheduleSlotFree(Driver $driver, Booking $incoming): void
    {
        if ($this->scheduleConflicts($driver, $incoming)) {
            abort(422, 'You have already accepted a booking at this time. Finish that trip or pick a slot at least 30 minutes after it.');
        }
    }

    private function scheduleConflicts(Driver $driver, Booking $incoming): bool
    {
        $window = $this->bookingWindow($incoming, true);
        if ($window === null) {
            return false;
        }
        [$start, $end] = $window;
        $buffer = 30;
        $conflicts = Booking::query()
            ->where('driver_id', $driver->id)
            ->where('id', '!=', $incoming->id)
            ->whereNotIn('status', BookingStatus::terminal())
            ->get();
        foreach ($conflicts as $row) {
            $other = $this->bookingWindow($row, false);
            if ($other === null) {
                continue;
            }
            $blockedStart = $other[0]->copy()->subMinutes($buffer);
            $blockedEnd = $other[1]->copy()->addMinutes($buffer);
            if ($start->lt($blockedEnd) && $end->gt($blockedStart)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}|null
     */
    private function bookingWindow(Booking $row, bool $incoming): ?array
    {
        $duration = $this->tripDurationMinutes($row);
        if ($row->scheduled_at) {
            $start = \Illuminate\Support\Carbon::parse($row->scheduled_at);
            if (! $incoming && in_array((string) $row->status, BookingStatus::terminal(), true)) {
                return null;
            }

            return [$start, $this->windowEnd($row, $start, $duration)];
        }
        if ($incoming) {
            $start = now();

            return [$start, $start->copy()->addMinutes($duration)];
        }
        if (in_array((string) $row->status, BookingStatus::openForDriver(), true)) {
            $start = $row->trip_started_at ? \Illuminate\Support\Carbon::parse($row->trip_started_at) : ($row->updated_at ? \Illuminate\Support\Carbon::parse($row->updated_at) : now());

            return [$start, $this->windowEnd($row, $start, $duration)];
        }

        return null;
    }

    private function windowEnd(Booking $row, \Illuminate\Support\Carbon $start, int $duration): \Illuminate\Support\Carbon
    {
        $end = $start->copy()->addMinutes($duration);
        if (strtoupper((string) $row->product) !== 'ROUND_WAY' || ! $row->return_at) {
            return $end;
        }
        try {
            $back = \Illuminate\Support\Carbon::parse($row->return_at)->addMinutes(max(30, intdiv($duration, 2)));
            if ($back->greaterThan($end)) {
                return $back;
            }
        } catch (\Throwable) {
        }

        return $end;
    }

    private function tripDurationMinutes(Booking $row): int
    {
        if ($this->isRental($row)) {
            $snapshot = is_array($row->quote_snapshot) ? $row->quote_snapshot : [];

            return app(RentalService::class)->bookedMinutes($snapshot);
        }
        $km = (float) ($row->distance_km ?? 0);
        if ($km <= 0) {
            $km = 10;
        }

        return max(30, (int) ceil($km / 0.5));
    }

    /**
     * @param  array<string, mixed>  $dto
     */
    private function ensureParcelShipment(Booking $booking, array $dto): void
    {
        if (strtoupper((string) $booking->product) !== 'PARCEL' || ! Schema::hasTable('parcel_shipments')) {
            return;
        }
        if (Schema::hasColumn('parcel_shipments', 'booking_id')) {
            $linked = DB::table('parcel_shipments')->where('booking_id', $booking->id)->first();
            if ($linked) {
                return;
            }
            $orphan = DB::table('parcel_shipments')
                ->where('customer_id', $booking->customer_id)
                ->whereNull('driver_id')
                ->where(function ($query) {
                    $query->whereNull('booking_id')->orWhere('booking_id', 0);
                })
                ->where('created_at', '>=', now()->subMinutes(15))
                ->orderByDesc('id')
                ->first();
            if ($orphan) {
                DB::table('parcel_shipments')->where('id', $orphan->id)->update([
                    'booking_id' => $booking->id,
                    'status' => in_array(strtolower((string) $orphan->status), ['created', 'paid'], true) ? 'paid' : $orphan->status,
                    'updated_at' => now(),
                ]);

                return;
            }
        }
        $row = [
            'public_ref' => $booking->public_ref,
            'customer_id' => $booking->customer_id,
            'category' => $booking->category,
            'pickup_text' => $booking->pickup_text,
            'drop_text' => $booking->drop_text,
            'pickup_lat' => $booking->pickup_lat,
            'pickup_lng' => $booking->pickup_lng,
            'drop_lat' => $booking->drop_lat,
            'drop_lng' => $booking->drop_lng,
            'distance_km' => $booking->distance_km,
            'contact_name' => $booking->passenger_name,
            'contact_phone' => $booking->passenger_phone,
            'quote_paise' => $booking->quote_paise,
            'payment_status' => 'unpaid',
            'status' => 'created',
            'booking_id' => $booking->id,
            'pickup_otp' => $booking->start_otp,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload = array_filter(
            $row,
            fn ($key) => Schema::hasColumn('parcel_shipments', $key),
            ARRAY_FILTER_USE_KEY,
        );
        DB::table('parcel_shipments')->insert($payload);
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
        if (str_contains($raw, 'UPI') || in_array($raw, ['ONLINE', 'PAYU', 'CARD', 'NETBANKING', 'RAZORPAY'], true)) {
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

    private function drivenKmFromLogs(Booking $booking): float
    {
        $stored = (float) ($booking->actual_distance_km ?? 0);
        if (! Schema::hasTable('trip_km_logs')) {
            return $stored;
        }
        $points = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderBy('id')->get(['lat', 'lng']);
        $km = 0.0;
        $prev = null;
        foreach ($points as $point) {
            if ($prev !== null) {
                $delta = Geo::haversineKm((float) $prev->lat, (float) $prev->lng, (float) $point->lat, (float) $point->lng);
                if ($delta >= 0.01 && $delta <= 8) {
                    $km += $delta;
                }
            }
            $prev = $point;
        }

        return round(max($stored, $km), 3);
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
        if ($this->isRental($booking) && (string) $booking->status === BookingStatus::RENTAL_IN_USE && $actor->role !== 'CUSTOMER') {
            return;
        }
        $this->ensureTripKmLogs();
        $prev = DB::table('trip_km_logs')->where('booking_id', $booking->id)->orderByDesc('id')->first();
        $prevLat = $prev ? (float) $prev->lat : ($actor->last_lat !== null ? (float) $actor->last_lat : (float) $booking->pickup_lat);
        $prevLng = $prev ? (float) $prev->lng : ($actor->last_lng !== null ? (float) $actor->last_lng : (float) $booking->pickup_lng);
        $delta = Geo::haversineKm($prevLat, $prevLng, $lat, $lng);
        $tracked = (float) ($booking->actual_distance_km ?? 0);
        $counted = 0.0;
        if ($delta >= 0.01 && $delta <= 8) {
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
        if ($this->isRental($booking)) {
            if (Schema::hasColumn('bookings', 'actual_distance_km')) {
                $booking->update(['actual_distance_km' => round(max(0, $tracked), 3)]);
            }

            return;
        }
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
        if ($product === 'ROUND_WAY') {
            $oneWayKm = (float) ($snapshot['oneWayKm'] ?? 0);
            if ($oneWayKm <= 0) {
                $oneWayKm = $bookedKm;
            }
            $roundKm = max($oneWayKm * 2, (float) ($snapshot['billedKm'] ?? 0));
            $covered = max($roundKm, $projected);
            $fareKm = max($oneWayKm, $covered / 2);
            $roundTrip = true;
        } else {
            $fareKm = max($bookedKm, $projected);
        }
        $lastBilled = (float) ($snapshot['liveBilledKm'] ?? $snapshot['billedKm'] ?? $bookedKm);
        $patch = ['actual_distance_km' => round(max(0, $drivenKm), 3)];
        if ($waitMinutes !== null || abs($fareKm - $lastBilled) >= 0.3) {
            if ($waitMinutes !== null) {
                $inputs['waitMinutes'] = $waitMinutes;
            }
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
            $promo = app(IncentiveService::class)->quotePromoDiscount((int) $booking->customer_id, (int) ($quote['totalPaise'] ?? 0));
            if ($promo > 0) {
                $quote['promoCreditPaise'] = $promo;
                $quote['totalPaise'] = max(0, (int) $quote['totalPaise'] - $promo);
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

    private function nextPublicRef(): string
    {
        do {
            $ref = 'KR'.now()->timezone('Asia/Kolkata')->format('ymd').strtoupper(Str::random(4));
        } while (Booking::query()->where('public_ref', $ref)->exists());

        return $ref;
    }

    private function notifyOps(Booking $booking, string $title): void
    {
        $email = (string) (DB::table('system_settings')->where('key', 'booking_notify_email')->value('value')
            ?? config('karnacab.booking_notify_email', 'karnaride@gmail.com'));
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

    private function settleAdvanceCommission(Booking $booking, int $advancePaise): void
    {
        if ($advancePaise <= 0) {
            return;
        }
        $snapshot = is_array($booking->quote_snapshot) ? $booking->quote_snapshot : [];
        $totalPaise = (int) ($snapshot['totalPaise'] ?? $booking->quote_paise ?? 0);
        $snapshot['advancePaidPaise'] = $advancePaise;
        $snapshot['commissionSettled'] = true;
        $snapshot['commissionPercent'] = 10;
        $snapshot['commissionPaise'] = $advancePaise;
        $snapshot['platformCommissionPaise'] = $advancePaise;
        $snapshot['driverEarningPaise'] = max(0, $totalPaise - $advancePaise);
        $booking->update($this->filterColumns([
            'quote_snapshot' => $snapshot,
            'commission_paise' => $advancePaise,
            'platform_commission_paise' => $advancePaise,
            'driver_earning_paise' => max(0, $totalPaise - $advancePaise),
        ]));
        $this->creditPlatformWallet((int) $booking->id, $advancePaise, $advancePaise);
    }

    private function debitDriverCommission(int $userId, int $bookingId, int $commissionPaise, int $grossPaise): void
    {
        app(WalletAccountService::class)->debitCommission($userId, $bookingId, $commissionPaise, $grossPaise);
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
                    'direction' => 'CREDIT',
                    'amount_paise' => $earningPaise,
                    'commission_paise' => $commissionPaise,
                    'gross_paise' => $grossPaise ?: $earningPaise,
                    'balance_before_paise' => $before,
                    'balance_after_paise' => $after,
                    'kind' => 'trip',
                    'status' => 'posted',
                    'note' => 'Trip fare ₹'.number_format($earningPaise / 100, 0),
                    'created_at' => now(),
                ]));
            }
        }
    }

    private function creditFleetWallet(int $fleetOwnerId, int $bookingId, int $amountPaise, int $grossPaise): void
    {
        if ($amountPaise <= 0 || ! Schema::hasTable('fleet_owners')) {
            return;
        }
        $userId = (int) DB::table('fleet_owners')->where('id', $fleetOwnerId)->value('user_id');
        if ($userId < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'FLEET_OWNER',
            $bookingId,
            $amountPaise,
            $grossPaise,
            'fleet_commission',
            'Fleet cut from the district 4% ₹'.number_format($amountPaise / 100, 0),
            ['FLEET', 'FLEET_OWNER'],
        );
    }

    private function creditPlatformWallet(int $bookingId, int $amountPaise, int $grossPaise): void
    {
        if ($amountPaise <= 0 || ! Schema::hasTable('users')) {
            return;
        }
        $userId = (int) (DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->value('id') ?? 0);
        if ($userId < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'PLATFORM',
            $bookingId,
            $amountPaise,
            $grossPaise,
            'platform_commission',
            'Platform commission ₹'.number_format($amountPaise / 100, 0),
            ['PLATFORM'],
        );
    }

    private function creditDistrictWallet(?int $districtId, int $bookingId, int $amountPaise, int $grossPaise): void
    {
        if ($amountPaise <= 0 || ! $districtId || ! Schema::hasTable('users')) {
            return;
        }
        $userId = (int) (DB::table('users')
            ->where('role', 'DISTRICT_HEAD')
            ->where('district_id', $districtId)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->value('id') ?? 0);
        if ($userId < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'DISTRICT_HEAD',
            $bookingId,
            $amountPaise,
            $grossPaise,
            'district_head_commission',
            'District Head share ₹'.number_format($amountPaise / 100, 0),
            ['DISTRICT_HEAD'],
        );
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

    /**
     * @param  array<string, mixed>  $dto
     */
    private function resolveDistrictId(array $dto, User $actor): ?int
    {
        $pickup = (string) ($dto['pickupText'] ?? $dto['pickup'] ?? '');
        $lat = isset($dto['pickupLat']) ? (float) $dto['pickupLat'] : (isset($dto['pickup_lat']) ? (float) $dto['pickup_lat'] : null);
        $lng = isset($dto['pickupLng']) ? (float) $dto['pickupLng'] : (isset($dto['pickup_lng']) ? (float) $dto['pickup_lng'] : null);
        $stateId = $actor->state_id ? (int) $actor->state_id : null;
        $fromPickup = \App\Support\DistrictLocator::resolve($lat, $lng, $pickup, $stateId);
        if ($fromPickup) {
            return $fromPickup;
        }
        if (isset($dto['districtId']) && (int) $dto['districtId'] > 0) {
            return (int) $dto['districtId'];
        }
        if ($actor->district_id) {
            return (int) $actor->district_id;
        }

        return null;
    }
}
