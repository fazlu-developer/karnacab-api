<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
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
        $canGoOnline = in_array(strtolower($kycStatus), ['approved', 'active'], true);
        $online = (bool) $driver->online;
        $duty = $driver->duty_status ?: ($online ? 'online' : 'offline');
        $canReceive = $canGoOnline && $online && ! in_array($duty, ['on_trip', 'busy'], true);
        $vehicle = $driver->vehicles->first();
        $wallet = DB::table('wallets')->where('owner_user_id', $actor->id)->first();
        $balance = (int) ($wallet->balance_paise ?? 0);
        $todayStart = now()->timezone('Asia/Kolkata')->startOfDay();
        $todayRides = \App\Models\Booking::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'COMPLETED')
            ->where('updated_at', '>=', $todayStart)
            ->count();
        $todayParcels = Schema::hasTable('parcel_shipments')
            ? DB::table('parcel_shipments')->where('driver_id', $driver->id)->where('status', 'delivered')->where('updated_at', '>=', $todayStart)->count()
            : 0;
        $pending = $canReceive
            ? \App\Models\Booking::query()->where('status', 'REQUESTED')->whereNull('driver_id')->where('created_at', '>=', now()->subMinutes(15))->count()
            : 0;
        $activeRide = \App\Models\Booking::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', ['ASSIGNED', 'DRIVER_ASSIGNED', 'DRIVER_ARRIVING', 'DRIVER_ARRIVED', 'STARTED', 'ONGOING'])
            ->orderByDesc('id')
            ->first();
        $activeParcel = Schema::hasTable('parcel_shipments')
            ? DB::table('parcel_shipments')->where('driver_id', $driver->id)->whereIn('status', ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'])->orderByDesc('id')->first()
            : null;
        $documents = $driver->documents->map(fn ($doc) => [
            'id' => (string) $doc->id,
            'type' => $doc->type ?? $doc->kind,
            'status' => $doc->status,
            'expiresAt' => $doc->expires_at,
        ])->all();

        return [
            'id' => (string) $driver->id,
            'userId' => (string) $driver->user_id,
            'name' => $driver->user?->name,
            'phone' => $driver->user?->phone,
            'email' => $driver->user?->email,
            'city' => $driver->city,
            'licenseNo' => $driver->license_no,
            'online' => $online,
            'dutyStatus' => $duty,
            'dutyLabel' => ucfirst(str_replace('_', ' ', (string) $duty)),
            'kycStatus' => $kycStatus,
            'canGoOnline' => $canGoOnline,
            'canReceiveOffers' => $canReceive,
            'offerBlockReason' => $canReceive ? null : ($canGoOnline ? 'Go online to receive offers' : 'Complete KYC to go online'),
            'nextStep' => $canGoOnline ? null : 'Complete KYC',
            'ratingAvg' => (float) $driver->rating_avg,
            'vehicles' => $driver->vehicles,
            'documents' => $documents,
            'today' => [
                'earningsPaise' => 0,
                'earningsRupees' => 0,
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
            'documentAlerts' => [],
            'vehicle' => $vehicle ? [
                'registrationNo' => $vehicle->registration_no,
                'category' => $vehicle->category,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'status' => $vehicle->status,
            ] : null,
            'activeJob' => $activeRide
                ? ['kind' => 'ride', 'id' => (string) $activeRide->id, 'status' => $activeRide->status]
                : ($activeParcel ? ['kind' => 'parcel', 'id' => (string) $activeParcel->id, 'status' => $activeParcel->status] : null),
        ];
    }

    public function offers(User $actor): array
    {
        $dash = $this->dashboard($actor);
        if (! $dash['canReceiveOffers']) {
            return ['offers' => []];
        }
        $rides = \App\Models\Booking::query()
            ->where('status', 'REQUESTED')
            ->whereNull('driver_id')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $presented = app(BookingService::class)->present($row);

                return array_merge($presented, [
                    'kind' => 'ride',
                    'pickup' => $presented['pickupText'],
                    'destination' => $presented['dropText'],
                    'estimatedFareRupees' => ($presented['quotePaise'] ?? 0) / 100,
                    'actions' => ['accept', 'reject'],
                ]);
            })
            ->all();
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
        $driver->update([
            'online' => $online,
            'duty_status' => $online ? 'online' : 'offline',
        ]);

        return $this->me($actor);
    }

    public function pingLocation(User $actor, float $lat, float $lng): array
    {
        User::query()->where('id', $actor->id)->update([
            'last_lat' => $lat,
            'last_lng' => $lng,
            'location_updated_at' => now(),
        ]);

        return ['ok' => true, 'lat' => $lat, 'lng' => $lng];
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
        return [
            'requiredDocs' => ['SELFIE', 'AADHAAR_FRONT', 'AADHAAR_BACK', 'PAN', 'LICENSE_FRONT', 'LICENSE_BACK', 'RC', 'INSURANCE'],
            'optionalDocs' => ['POLLUTION', 'PERMIT'],
            'cities' => DB::table('districts')->orderBy('name')->pluck('name'),
        ];
    }

    public function kycSnapshot(User $actor): array
    {
        return $this->me($actor);
    }

    public function patchProfile(User $actor, array $data): array
    {
        $actor->update(array_filter([
            'name' => $data['name'] ?? null,
            'emergency_name' => $data['emergencyName'] ?? null,
            'emergency_phone' => $data['emergencyPhone'] ?? null,
        ], fn ($v) => $v !== null));
        $driver = Driver::query()->where('user_id', $actor->id)->first();
        $driver?->update(array_filter([
            'city' => $data['city'] ?? null,
            'license_no' => $data['licenseNo'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->me($actor);
    }

    public function submitKyc(User $actor): array
    {
        Driver::query()->where('user_id', $actor->id)->update([
            'kyc_status' => 'submitted',
            'application_submitted_at' => now(),
        ]);

        return $this->me($actor);
    }

    public function reviewKyc(string $driverId, string $status, ?string $reason = null): array
    {
        $driver = Driver::query()->findOrFail($driverId);
        $driver->update([
            'kyc_status' => $status,
            'kyc_rejected_reason' => $reason,
        ]);
        if ($status === 'approved') {
            User::query()->where('id', $driver->user_id)->update(['status' => 'ACTIVE']);
        }

        return ['id' => (string) $driver->id, 'kycStatus' => $driver->kyc_status];
    }
}
