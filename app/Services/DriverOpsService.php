<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $blocked = in_array(strtolower($kycStatus), ['rejected', 'suspended'], true);
        $canGoOnline = ! $blocked;
        $online = (bool) $driver->online;
        $duty = $driver->duty_status ?: ($online ? 'online' : 'offline');
        $canReceive = $canGoOnline && $online && in_array(strtolower((string) $duty), ['online'], true);
        $vehicle = $driver->vehicles->first();
        $wallet = DB::table('wallets')->where('owner_user_id', $actor->id)->first();
        $balance = (int) ($wallet->balance_paise ?? 0);
        $todayStart = now()->timezone('Asia/Kolkata')->startOfDay();
        $todayRides = \App\Models\Booking::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'COMPLETED')
            ->where('updated_at', '>=', $todayStart)
            ->count();
        $todayEarnPaise = Schema::hasTable('wallet_ledger')
            ? (int) DB::table('wallet_ledger')
                ->where('owner_user_id', $actor->id)
                ->where('direction', 'credit')
                ->where('created_at', '>=', $todayStart)
                ->sum('amount_paise')
            : 0;
        $todayParcels = Schema::hasTable('parcel_shipments')
            ? DB::table('parcel_shipments')->where('driver_id', $driver->id)->where('status', 'delivered')->where('updated_at', '>=', $todayStart)->count()
            : 0;
        $pending = $canReceive
            ? count(app(\App\Services\BookingService::class)->offersForDriver($actor))
            : 0;
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

        return [
            'id' => (string) $driver->id,
            'userId' => (string) $driver->user_id,
            'name' => $driver->user?->name,
            'phone' => $driver->user?->phone,
            'email' => $driver->user?->email,
            'gender' => $driver->user?->gender,
            'avatarUrl' => app(KycDocumentService::class)->previewUrl($driver->user?->avatar_path ?? null),
            'dateOfBirth' => optional($driver->user?->date_of_birth)->toDateString() ?? $driver->user?->date_of_birth,
            'city' => $driver->city,
            'stateId' => $driver->user?->state_id,
            'districtId' => $driver->user?->district_id,
            'vehicleFamily' => $driver->vehicle_family,
            'aadhaarLast4' => $driver->aadhaar_last4,
            'panLast4' => $driver->pan_last4,
            'licenseNo' => $driver->license_no,
            'licenseExpiresAt' => $driver->license_expires_at,
            'online' => $online,
            'dutyStatus' => $duty,
            'dutyLabel' => ucfirst(str_replace('_', ' ', (string) $duty)),
            'kycStatus' => $kycStatus,
            'canGoOnline' => $canGoOnline,
            'canReceiveOffers' => $canReceive,
            'offerBlockReason' => $canReceive ? null : ($blocked ? 'Account is blocked' : 'Tap Go online when you are ready.'),
            'nextStep' => $blocked ? 'Contact support' : null,
            'ratingAvg' => (float) $driver->rating_avg,
            'vehicles' => $driver->vehicles,
            'documents' => $documents,
            'today' => [
                'earningsPaise' => $todayEarnPaise,
                'earningsRupees' => $todayEarnPaise / 100,
                'trips' => $todayRides + $todayParcels,
                'rides' => $todayRides,
                'parcels' => $todayParcels,
            ],
            'pendingRequests' => $pending,
            'wallet' => [
                'balancePaise' => $balance,
                'balanceRupees' => $balance / 100,
            ],
            'incentives' => [],
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
        ];
    }

    public function offers(User $actor): array
    {
        $dash = $this->dashboard($actor);
        if (! $dash['canReceiveOffers']) {
            return ['offers' => []];
        }
        $rides = app(BookingService::class)->offersForDriver($actor);
        $parcels = [];
        if (Schema::hasTable('parcel_shipments')) {
            $parcels = DB::table('parcel_shipments')
                ->whereNull('driver_id')
                ->whereIn('status', ['created', 'paid'])
                ->where('created_at', '>=', now()->subMinutes(15))
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn ($row) => array_merge(app(AppSurfaceService::class)->presentParcel($row), [
                    'actions' => ['accept', 'reject'],
                ]))
                ->all();
        }

        return ['offers' => array_values(array_merge($rides, $parcels))];
    }

    public function trips(User $actor): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $rides = \App\Models\Booking::query()->where('driver_id', $driver->id)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => [
            'kind' => 'ride',
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'status' => $row->status,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'product' => $row->product,
            'category' => $row->category,
            'quotePaise' => (int) $row->quote_paise,
            'quoteRupees' => ((int) $row->quote_paise) / 100,
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
        ])->all();
        $parcels = [];
        if (Schema::hasTable('parcel_shipments')) {
            $parcels = DB::table('parcel_shipments')->where('driver_id', $driver->id)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => [
                'kind' => 'parcel',
                'id' => (string) $row->id,
                'status' => $row->status,
                'pickupText' => $row->pickup_text,
                'dropText' => $row->drop_text,
                'product' => 'PARCEL',
                'category' => $row->category,
                'quotePaise' => (int) ($row->quote_paise ?? 0),
                'quoteRupees' => ((int) ($row->quote_paise ?? 0)) / 100,
                'updatedAt' => $row->updated_at,
            ])->all();
        }

        return ['trips' => array_values(array_merge($rides, $parcels))];
    }

    public function documents(User $actor): array
    {
        $dash = $this->dashboard($actor);

        return ['documents' => $dash['documents'], 'alerts' => $dash['documentAlerts']];
    }

    public function earnings(User $actor): array
    {
        $dash = $this->dashboard($actor);

        return [
            'today' => $dash['today'],
            'wallet' => $dash['wallet'],
            'withdrawalsEnabled' => false,
        ];
    }

    public function setOnline(User $actor, bool $online): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        if ($online && in_array(strtolower((string) $driver->duty_status), ['on_trip', 'on_delivery'], true)) {
            return $this->me($actor);
        }
        if (! $online && in_array(strtolower((string) $driver->duty_status), ['on_trip', 'on_delivery'], true)) {
            abort(422, 'Complete the trip before going offline.');
        }
        $driver->update([
            'online' => $online,
            'duty_status' => $online ? 'online' : 'offline',
        ]);

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
        $allowed = \App\Support\ServiceArea::allows($lat, $lng);
        $live = null;
        if ($bookingId) {
            try {
                $live = app(BookingService::class)->live($actor, $bookingId);
            } catch (\Throwable $e) {
                Log::warning('booking.live_after_location_failed', ['message' => $e->getMessage()]);
            }
        }

        return [
            'ok' => true,
            'lat' => $lat,
            'lng' => $lng,
            'heading' => $heading,
            'bookingId' => $bookingId,
            'allowed' => $allowed,
            'comingSoon' => ! $allowed,
            'serviceArea' => \App\Support\ServiceArea::label($lat, $lng),
            'message' => $allowed ? null : \App\Support\ServiceArea::comingSoonMessage(),
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
        $actor->update(array_filter([
            'name' => $data['name'] ?? ($name !== '' ? $name : null),
            'email' => isset($data['email']) && $data['email'] !== '' ? strtolower((string) $data['email']) : null,
            'emergency_name' => $data['emergencyName'] ?? null,
            'emergency_phone' => $data['emergencyPhone'] ?? null,
            'last_address' => $data['address'] ?? null,
            'state_id' => $data['stateId'] ?? $data['state_id'] ?? null,
            'district_id' => $data['districtId'] ?? $data['district_id'] ?? null,
            'gender' => isset($data['gender']) ? strtoupper((string) $data['gender']) : null,
            'date_of_birth' => $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $driver = Driver::query()->where('user_id', $actor->id)->first();
        if ($driver) {
            $city = $data['city'] ?? null;
            if (! empty($data['districtId']) && Schema::hasTable('districts')) {
                $city = $city ?: DB::table('districts')->where('id', $data['districtId'])->value('name');
            }
            $driverUpdates = array_filter([
                'city' => $city,
                'license_no' => $data['licenseNo'] ?? $data['license_no'] ?? null,
                'license_expires_at' => $data['licenseExpiresAt'] ?? $data['license_expires_at'] ?? null,
                'vehicle_family' => isset($data['vehicleFamily']) ? strtoupper((string) $data['vehicleFamily']) : ($data['vehicle_family'] ?? null),
                'bank_account_holder' => $data['accountHolder'] ?? $data['bank_account_holder'] ?? null,
                'bank_ifsc' => isset($data['ifsc']) ? strtoupper((string) $data['ifsc']) : null,
                'upi_id' => $data['upiId'] ?? $data['upi_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if (! empty($data['aadhaar']) || ! empty($data['aadhaarNumber'])) {
                $aadhaar = preg_replace('/\D+/', '', (string) ($data['aadhaar'] ?? $data['aadhaarNumber'])) ?? '';
                abort_unless(preg_match('/^\d{12}$/', $aadhaar), 422, 'Enter a valid 12-digit Aadhaar number');
                $driverUpdates['aadhaar_last4'] = substr($aadhaar, -4);
                $driverUpdates['aadhaar_hash'] = hash('sha256', $aadhaar);
                $driverUpdates['id_type'] = 'AADHAAR';
                $driverUpdates['id_last4'] = substr($aadhaar, -4);
            }
            if (! empty($data['pan']) || ! empty($data['panNumber'])) {
                $pan = strtoupper(preg_replace('/\s+/', '', (string) ($data['pan'] ?? $data['panNumber'])) ?? '');
                abort_unless(preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan), 422, 'Enter a valid PAN');
                $driverUpdates['pan_last4'] = substr($pan, -4);
                $driverUpdates['pan_hash'] = hash('sha256', $pan);
            }
            if (! empty($data['accountNumber'])) {
                $acct = preg_replace('/\D+/', '', (string) $data['accountNumber']) ?? '';
                $driverUpdates['bank_account_last4'] = substr($acct, -4);
                $driverUpdates['bank_account_hash'] = hash('sha256', $acct);
            }
            if (! empty($data['termsAccepted'])) {
                $driverUpdates['terms_accepted_at'] = now();
            }
            if ($driverUpdates) {
                $driver->update($driverUpdates);
            }
            $this->upsertVehicle($driver, $data);
        }

        return $this->me($actor->fresh());
    }

    public function submitKyc(User $actor): array
    {
        Driver::query()->where('user_id', $actor->id)->update([
            'kyc_status' => 'under_review',
            'application_submitted_at' => now(),
        ]);

        return $this->me($actor);
    }

    public function kycSnapshot(User $actor): array
    {
        return $this->me($actor);
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
        }

        return ['id' => (string) $driver->id, 'kycStatus' => $driver->kyc_status];
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
        if ($vehicle) {
            $vehicle->update($payload);
        } else {
            $payload['created_at'] = now();
            Vehicle::query()->create($payload);
        }
    }
}
