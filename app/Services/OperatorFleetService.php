<?php

namespace App\Services;

use App\Models\User;
use App\Support\FleetOnboarding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OperatorFleetService
{
    public const VEHICLE_TYPES = [
        'BIKE', 'AUTO', 'E_RICKSHAW', 'MINI', 'SEDAN', 'SUV', 'TRAVELLER', 'TEMPO', 'PICKUP', 'SMALL_TRUCK',
    ];

    public function capabilities(User $actor): array
    {
        $fleet = $this->fleetRow($actor);
        $driver = Schema::hasTable('drivers')
            ? DB::table('drivers')->where('user_id', $actor->id)->first()
            : null;

        return [
            'canOperatorMode' => $fleet !== null || $actor->role === 'FLEET_OWNER',
            'canDriverMode' => $driver !== null,
            'fleetOwnerId' => $fleet?->id,
            'driverId' => $driver?->id,
            'role' => $actor->role,
        ];
    }

    public function onboarding(User $actor): array
    {
        $fleet = $this->requireFleet($actor, false);

        return $this->presentOnboarding($actor, $fleet);
    }

    public function saveOnboarding(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor, false);
        FleetOnboarding::ensureColumns();
        $type = strtoupper(trim((string) ($data['companyType'] ?? $data['company_type'] ?? $fleet->company_type ?? '')));
        abort_unless($type === '' || in_array($type, FleetOnboarding::COMPANY_TYPES, true), 422, 'Select a valid company type.');
        $name = trim((string) ($data['tradeName'] ?? $data['trade_name'] ?? $data['companyName'] ?? $fleet->trade_name ?? ''));
        $ownerName = trim((string) ($data['ownerName'] ?? $data['name'] ?? $actor->name ?? ''));
        $patch = ['updated_at' => now()];
        if ($type !== '' && Schema::hasColumn('fleet_owners', 'company_type')) {
            $patch['company_type'] = $type;
        }
        if ($name !== '') {
            abort_unless(strlen($name) >= 2, 422, 'Enter the company name.');
            $patch['trade_name'] = $name;
        }
        foreach ([
            'gstin' => $data['gstin'] ?? $data['gst'] ?? null,
            'address' => $data['address'] ?? null,
            'pan' => $data['pan'] ?? null,
            'contact_email' => $data['email'] ?? $data['contactEmail'] ?? null,
            'contact_phone' => $data['phone'] ?? $data['contactPhone'] ?? null,
            'state_id' => $data['stateId'] ?? $data['state_id'] ?? null,
            'district_id' => $data['districtId'] ?? $data['district_id'] ?? null,
        ] as $col => $value) {
            if ($value !== null && $value !== '' && Schema::hasColumn('fleet_owners', $col)) {
                $patch[$col] = $value;
            }
        }
        if (strtolower((string) ($fleet->kyc_status ?? '')) === 'rejected' && Schema::hasColumn('fleet_owners', 'kyc_status')) {
            $patch['kyc_status'] = 'pending';
        }
        DB::table('fleet_owners')->where('id', $fleet->id)->update($patch);
        if ($ownerName !== '' && strlen($ownerName) >= 2) {
            DB::table('users')->where('id', $actor->id)->update(['name' => $ownerName, 'updated_at' => now()]);
            $actor->name = $ownerName;
        }

        return $this->presentOnboarding($actor->fresh(), DB::table('fleet_owners')->where('id', $fleet->id)->first());
    }

    public function uploadCompanyDocument(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor, false);
        FleetOnboarding::ensureColumns();
        $type = strtoupper(trim((string) ($data['type'] ?? '')));
        abort_unless(in_array($type, FleetOnboarding::COMPANY_DOCS, true), 422, 'Unknown company document type.');
        $saved = $this->storeBinary($data, 'fleet/'.$fleet->id);
        $docs = FleetOnboarding::documents($fleet);
        $docs = array_values(array_filter($docs, fn ($row) => strtoupper((string) ($row['type'] ?? '')) !== $type));
        $docs[] = [
            'type' => $type,
            'status' => 'pending',
            'storageKey' => $saved['path'],
            'originalName' => $saved['name'],
            'mime' => $saved['mime'],
            'uploadedAt' => now()->toIso8601String(),
        ];
        DB::table('fleet_owners')->where('id', $fleet->id)->update([
            'documents_json' => json_encode($docs),
            'updated_at' => now(),
        ]);

        return $this->presentOnboarding($actor, DB::table('fleet_owners')->where('id', $fleet->id)->first());
    }

    public function submitOnboarding(User $actor): array
    {
        $fleet = $this->requireFleet($actor, false);
        FleetOnboarding::ensureColumns();
        abort_unless(strlen(trim((string) ($fleet->trade_name ?? ''))) >= 2, 422, 'Enter company information first.');
        abort_unless(trim((string) ($fleet->company_type ?? '')) !== '', 422, 'Select a company type.');
        $docs = FleetOnboarding::documents($fleet);
        abort_unless(count($docs) >= 1, 422, 'Upload at least one company document.');
        $patch = [
            'status' => 'PENDING',
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('fleet_owners', 'kyc_status')) {
            $patch['kyc_status'] = 'under_review';
        }
        if (Schema::hasColumn('fleet_owners', 'submitted_at')) {
            $patch['submitted_at'] = now();
        }
        DB::table('fleet_owners')->where('id', $fleet->id)->update($patch);
        DB::table('users')->where('id', $actor->id)->update(['status' => 'PENDING', 'updated_at' => now()]);

        return $this->presentOnboarding($actor->fresh(), DB::table('fleet_owners')->where('id', $fleet->id)->first());
    }

    public function dashboard(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicles = $this->vehicleRows($fleet);
        $onTripIds = $this->activeTripDriverIds($vehicles);
        $drivers = $this->driversQuery($fleet)->get();
        $today = now()->timezone('Asia/Kolkata')->startOfDay();
        $trips = $this->tripsQuery($fleet);
        $todayBookings = (clone $trips)->where('bookings.created_at', '>=', $today);
        $completedToday = (clone $trips)->where('bookings.status', 'COMPLETED')->where('bookings.updated_at', '>=', $today);
        $cancelledToday = (clone $trips)->where('bookings.status', 'CANCELLED')->where('bookings.updated_at', '>=', $today);
        $onLeave = $drivers->filter(fn ($d) => strtolower((string) $d->duty_status) === 'on_leave')->count();
        $online = $drivers->filter(fn ($d) => (int) $d->online === 1 && strtolower((string) $d->duty_status) !== 'on_leave')->count();
        $vehicleCards = array_map(fn ($row) => $this->presentVehicle($row, $onTripIds), $vehicles);
        $activeVehicles = count(array_filter($vehicleCards, fn ($v) => in_array($v['status'], ['available', 'assigned', 'on_trip'], true)));
        $availableVehicles = count(array_filter($vehicleCards, fn ($v) => $v['status'] === 'available'));
        $onTripVehicles = count(array_filter($vehicleCards, fn ($v) => $v['status'] === 'on_trip'));

        return [
            'fleet' => $this->presentFleet($fleet, $actor),
            'capabilities' => $this->capabilities($actor),
            'kpis' => [
                'totalVehicles' => count($vehicles),
                'activeVehicles' => $activeVehicles,
                'availableVehicles' => $availableVehicles,
                'onTripVehicles' => $onTripVehicles,
                'totalDrivers' => $drivers->count(),
                'onlineDrivers' => $online,
                'offlineDrivers' => max(0, $drivers->count() - $online - $onLeave),
                'driversOnLeave' => $onLeave,
                'todayBookings' => (clone $todayBookings)->count(),
                'todayCompleted' => (clone $completedToday)->count(),
                'todayCancelled' => (clone $cancelledToday)->count(),
                'todayEarningsRupees' => ((int) (clone $completedToday)->sum('bookings.quote_paise')) / 100,
            ],
        ];
    }

    public function vehicles(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $rows = $this->vehicleRows($fleet);
        $onTrip = $this->activeTripDriverIds($rows);

        return ['vehicles' => array_map(fn ($row) => $this->presentVehicle($row, $onTrip), $rows)];
    }

    public function vehicle(User $actor, int $id): array
    {
        $fleet = $this->requireFleet($actor);
        $row = $this->requireVehicle($fleet, $id);
        $onTrip = $this->activeTripDriverIds([$row]);
        $payload = $this->presentVehicle($row, $onTrip);
        $payload['documents'] = $this->docs('vehicle_documents', 'vehicle_id', $id);
        $payload['trips'] = $this->tripsQuery($fleet)->where('bookings.vehicle_id', $id)->orderByDesc('bookings.id')->limit(30)->get()->map(fn ($t) => $this->presentTrip($t))->all();
        $payload['assignments'] = $this->assignmentsForVehicle($fleet, $id);
        $payload['eligibleDrivers'] = $this->eligibleDrivers($actor, $id);

        return $payload;
    }

    public function addVehicle(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $registration = strtoupper(preg_replace('/\s+/', '', (string) ($data['registrationNo'] ?? $data['registration_no'] ?? '')));
        abort_unless(strlen($registration) >= 4, 422, 'Enter a valid registration number.');
        $category = strtoupper((string) ($data['category'] ?? $data['vehicleType'] ?? 'SEDAN'));
        $districtId = (int) ($data['districtId'] ?? $data['district_id'] ?? $actor->district_id ?? 0);
        $payload = [
            'fleet_owner_id' => $fleet->id,
            'registration_no' => $registration,
            'category' => $category,
            'status' => 'pending_review',
            'brand' => $data['make'] ?? $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'color' => $data['color'] ?? null,
            'fuel' => $data['fuelType'] ?? $data['fuel'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('vehicles', 'district_id') && $districtId > 0) {
            $payload['district_id'] = $districtId;
        }
        if (Schema::hasColumn('vehicles', 'state_id')) {
            $payload['state_id'] = $data['stateId'] ?? $data['state_id'] ?? $actor->state_id ?? $fleet->state_id ?? null;
        }
        if (Schema::hasColumn('vehicles', 'individual_driver_id')) {
            $payload['individual_driver_id'] = null;
        }
        try {
            $id = DB::table('vehicles')->insertGetId($payload);
        } catch (QueryException $e) {
            abort(409, 'Registration number already exists.');
        }
        $this->audit($actor, $fleet, 'vehicle.created', 'vehicle', $id, null, $payload);
        foreach ((array) ($data['documents'] ?? []) as $doc) {
            if (is_array($doc)) {
                $this->storeOwnedDocument('vehicle_documents', 'vehicle_id', $id, $doc, 'vehicle/'.$id);
            }
        }

        return $this->vehicle($actor, $id);
    }

    public function updateVehicle(User $actor, int $id, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $this->requireVehicle($fleet, $id);
        $patch = ['updated_at' => now()];
        $map = [
            'brand' => $data['make'] ?? $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'year' => $data['year'] ?? null,
            'color' => $data['color'] ?? null,
            'fuel' => $data['fuelType'] ?? $data['fuel'] ?? null,
            'category' => isset($data['category']) ? strtoupper((string) $data['category']) : (isset($data['vehicleType']) ? strtoupper((string) $data['vehicleType']) : null),
            'status' => $data['status'] ?? null,
        ];
        foreach ($map as $col => $value) {
            if ($value !== null && $value !== '') {
                $patch[$col] = $value;
            }
        }
        if (! empty($data['registrationNo'] ?? $data['registration_no'])) {
            $patch['registration_no'] = strtoupper(preg_replace('/\s+/', '', (string) ($data['registrationNo'] ?? $data['registration_no'])));
        }
        DB::table('vehicles')->where('id', $id)->where('fleet_owner_id', $fleet->id)->update($patch);
        $this->audit($actor, $fleet, 'vehicle.updated', 'vehicle', $id, null, $patch);

        return $this->vehicle($actor, $id);
    }

    public function drivers(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $rows = $this->driversQuery($fleet)
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->select('drivers.*', 'users.name', 'users.email', 'users.phone', 'users.status as account_status')
            ->orderByDesc('drivers.id')
            ->get();

        return ['drivers' => $rows->map(fn ($row) => $this->presentDriver($row, $fleet))->all()];
    }

    public function driver(User $actor, int $id): array
    {
        $fleet = $this->requireFleet($actor);
        $row = $this->requireDriver($fleet, $id);
        $payload = $this->presentDriver($row, $fleet);
        $payload['documents'] = $this->docs('driver_documents', 'driver_id', $id);
        $payload['trips'] = $this->tripsQuery($fleet)->where('bookings.driver_id', $id)->orderByDesc('bookings.id')->limit(30)->get()->map(fn ($t) => $this->presentTrip($t))->all();
        $payload['assignments'] = $this->assignmentsForDriver($fleet, $id);

        return $payload;
    }

    public function addDriver(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? $data['mobile'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        abort_unless(strlen($name) >= 2, 422, 'Enter the driver name.');
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($phone) === 10, 422, 'Enter email or a 10-digit mobile number.');
        if ($email === '') {
            $email = $phone.'@fleet.karnacab.local';
        }
        $existing = DB::table('users')->where('email', $email)->when($phone, fn ($q) => $q->orWhere('phone', $phone))->first();
        if ($existing) {
            abort_unless($existing->role === 'DRIVER', 422, 'That account is not a driver.');
            $driverId = DB::table('drivers')->where('user_id', $existing->id)->value('id');
            abort_unless($driverId, 422, 'Driver profile missing for this user.');
            DB::table('drivers')->where('id', $driverId)->update([
                'fleet_owner_id' => $fleet->id,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $fleet, 'driver.linked', 'driver', (int) $driverId, null, ['userId' => $existing->id]);

            return $this->driver($actor, (int) $driverId);
        }
        $userId = DB::table('users')->insertGetId([
            'role' => 'DRIVER',
            'status' => 'PENDING',
            'name' => $name,
            'email' => $email,
            'phone' => $phone ?: null,
            'password_hash' => Hash::make((string) ($data['password'] ?? Str::password(12))),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverPayload = [
            'user_id' => $userId,
            'fleet_owner_id' => $fleet->id,
            'online' => false,
            'duty_status' => 'offline',
            'kyc_status' => 'under_review',
            'license_no' => $data['licenseNo'] ?? $data['license_no'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('drivers', 'city') && ! empty($data['city'])) {
            $driverPayload['city'] = $data['city'];
        }
        if (Schema::hasColumn('drivers', 'application_submitted_at')) {
            $driverPayload['application_submitted_at'] = now();
        }
        $driverId = DB::table('drivers')->insertGetId($driverPayload);
        foreach ((array) ($data['documents'] ?? []) as $doc) {
            if (is_array($doc)) {
                $this->storeOwnedDocument('driver_documents', 'driver_id', $driverId, $doc, 'kyc/'.$driverId);
            }
        }
        $this->audit($actor, $fleet, 'driver.created', 'driver', $driverId, null, ['userId' => $userId]);

        return $this->driver($actor, $driverId);
    }

    public function assignDriver(User $actor, int $vehicleId, int $driverId, ?string $reason = null): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicle = $this->requireVehicle($fleet, $vehicleId);
        abort_if(in_array(strtolower((string) $vehicle->status), ['pending_review', 'pending', 'rejected', 'maintenance', 'blocked', 'suspended'], true), 422, 'Vehicle is not assignable until admin approval.');
        $driver = $this->requireDriver($fleet, $driverId);
        $this->assertAssignable($driver);
        $this->closeActiveAssignment($fleet, (int) $vehicle->id, 'replaced', $actor->id);
        DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->where('driver_id', $driverId)->where('id', '!=', $vehicleId)->update([
            'driver_id' => null,
            'status' => 'available',
            'updated_at' => now(),
        ]);
        DB::table('vehicles')->where('id', $vehicleId)->where('fleet_owner_id', $fleet->id)->update([
            'driver_id' => $driverId,
            'status' => 'assigned',
            'updated_at' => now(),
        ]);
        $this->openAssignment($fleet, $vehicleId, $driverId, $actor->id, $reason ?? 'assigned');
        $this->audit($actor, $fleet, 'driver.assigned', 'vehicle', $vehicleId, ['driverId' => $vehicle->driver_id], ['driverId' => $driverId]);

        return $this->vehicle($actor, $vehicleId);
    }

    public function unassignDriver(User $actor, int $vehicleId, ?string $reason = null): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicle = $this->requireVehicle($fleet, $vehicleId);
        $this->closeActiveAssignment($fleet, $vehicleId, $reason ?? 'unassigned', $actor->id);
        DB::table('vehicles')->where('id', $vehicleId)->where('fleet_owner_id', $fleet->id)->update([
            'driver_id' => null,
            'status' => 'available',
            'updated_at' => now(),
        ]);
        $this->audit($actor, $fleet, 'driver.unassigned', 'vehicle', $vehicleId, ['driverId' => $vehicle->driver_id], null);

        return $this->vehicle($actor, $vehicleId);
    }

    public function replaceDriver(User $actor, int $vehicleId, int $newDriverId, ?string $reason = null): array
    {
        return $this->assignDriver($actor, $vehicleId, $newDriverId, $reason ?? 'replacement');
    }

    public function eligibleDrivers(User $actor, ?int $vehicleId = null): array
    {
        $fleet = $this->requireFleet($actor);
        $assignedIds = DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->whereNotNull('driver_id')->pluck('driver_id')->all();
        $current = $vehicleId ? DB::table('vehicles')->where('id', $vehicleId)->where('fleet_owner_id', $fleet->id)->value('driver_id') : null;
        $rows = $this->driversQuery($fleet)
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->select('drivers.*', 'users.name', 'users.email', 'users.phone', 'users.status as account_status')
            ->get();
        $list = [];
        foreach ($rows as $row) {
            $kyc = strtolower((string) ($row->kyc_status ?? ''));
            $verified = in_array($kyc, ['verified', 'approved', 'active'], true);
            $onLeave = strtolower((string) $row->duty_status) === 'on_leave';
            $suspended = strtolower((string) ($row->account_status ?? '')) === 'suspended';
            $busy = in_array((int) $row->id, $assignedIds, true) && (int) $row->id !== (int) $current;
            $eligible = $verified && ! $onLeave && ! $suspended && ! $busy;
            $item = $this->presentDriver($row, $fleet);
            $item['eligible'] = $eligible;
            $item['unavailableReason'] = ! $verified ? 'Not verified' : ($onLeave ? 'On leave' : ($suspended ? 'Suspended' : ($busy ? 'Assigned to another vehicle' : null)));
            $list[] = $item;
        }

        return $list;
    }

    public function leaveList(User $actor, ?string $status = null): array
    {
        $fleet = $this->requireFleet($actor);
        if (! Schema::hasTable('driver_leave_requests')) {
            return ['leaves' => []];
        }
        $q = DB::table('driver_leave_requests')->where('fleet_owner_id', $fleet->id);
        if ($status) {
            $q->where('status', strtoupper($status));
        }
        $rows = $q->orderByDesc('id')->limit(100)->get();

        return ['leaves' => $rows->map(fn ($row) => $this->presentLeave($row, $fleet))->all()];
    }

    public function createLeave(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        abort_unless(Schema::hasTable('driver_leave_requests'), 501, 'Leave table is not ready.');
        $driverId = (int) ($data['driverId'] ?? $data['driver_id'] ?? 0);
        $driver = $this->requireDriver($fleet, $driverId);
        $id = DB::table('driver_leave_requests')->insertGetId([
            'fleet_owner_id' => $fleet->id,
            'driver_id' => $driverId,
            'vehicle_id' => DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->where('driver_id', $driverId)->value('id'),
            'status' => 'PENDING',
            'reason' => $data['reason'] ?? null,
            'starts_on' => $data['startsOn'] ?? $data['starts_on'] ?? now()->toDateString(),
            'ends_on' => $data['endsOn'] ?? $data['ends_on'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actor, $fleet, 'leave.created', 'leave', $id, null, ['driverId' => $driverId]);

        return $this->presentLeave(DB::table('driver_leave_requests')->where('id', $id)->first(), $fleet);
    }

    public function reviewLeave(User $actor, int $id, string $decision, ?string $note = null): array
    {
        $fleet = $this->requireFleet($actor);
        abort_unless(Schema::hasTable('driver_leave_requests'), 501, 'Leave table is not ready.');
        $row = DB::table('driver_leave_requests')->where('id', $id)->where('fleet_owner_id', $fleet->id)->first();
        abort_if($row === null, 404, 'Leave request not found.');
        $status = strtoupper($decision) === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        DB::table('driver_leave_requests')->where('id', $id)->update([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => $note,
            'updated_at' => now(),
        ]);
        $replacementRequired = false;
        if ($status === 'APPROVED') {
            DB::table('drivers')->where('id', $row->driver_id)->where('fleet_owner_id', $fleet->id)->update([
                'duty_status' => 'on_leave',
                'online' => false,
                'updated_at' => now(),
            ]);
            $replacementRequired = (bool) DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->where('driver_id', $row->driver_id)->exists();
        }
        $this->audit($actor, $fleet, 'leave.'.strtolower($status), 'leave', $id, ['status' => $row->status], ['status' => $status]);
        $fresh = DB::table('driver_leave_requests')->where('id', $id)->first();
        $payload = $this->presentLeave($fresh, $fleet);
        $payload['replacementRequired'] = $replacementRequired;

        return $payload;
    }

    public function bookings(User $actor, array $query = []): array
    {
        $fleet = $this->requireFleet($actor);
        $q = $this->tripsQuery($fleet);
        if (! empty($query['status'])) {
            $q->where('bookings.status', strtoupper((string) $query['status']));
        }
        if (! empty($query['driverId'])) {
            $q->where('bookings.driver_id', (int) $query['driverId']);
        }
        if (! empty($query['vehicleId'])) {
            $q->where('bookings.vehicle_id', (int) $query['vehicleId']);
        }
        if (! empty($query['from'])) {
            $q->where('bookings.created_at', '>=', $query['from']);
        }
        if (! empty($query['to'])) {
            $q->where('bookings.created_at', '<=', $query['to']);
        }
        $rows = $q->orderByDesc('bookings.id')->limit(120)->get();

        return ['bookings' => $rows->map(fn ($row) => $this->presentTrip($row))->all()];
    }

    public function booking(User $actor, int $id): array
    {
        $fleet = $this->requireFleet($actor);
        $row = $this->tripsQuery($fleet)->where('bookings.id', $id)->first();
        abort_if($row === null, 404, 'Booking not found.');

        return $this->presentTrip($row);
    }

    public function manualBooking(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicleId = (int) ($data['vehicleId'] ?? $data['vehicle_id'] ?? 0);
        $driverId = (int) ($data['driverId'] ?? $data['driver_id'] ?? 0);
        if ($vehicleId) {
            $this->requireVehicle($fleet, $vehicleId);
        }
        if ($driverId) {
            $this->requireDriver($fleet, $driverId);
        }
        $phone = preg_replace('/\D+/', '', (string) ($data['customerMobile'] ?? $data['phone'] ?? ''));
        abort_unless(strlen($phone) === 10, 422, 'Enter a valid customer mobile number.');
        $customer = DB::table('users')->where('phone', $phone)->first();
        if (! $customer) {
            $customerId = DB::table('users')->insertGetId([
                'role' => 'CUSTOMER',
                'status' => 'ACTIVE',
                'name' => $data['customerName'] ?? 'Manual booking',
                'email' => $phone.'@manual.karnacab.local',
                'phone' => $phone,
                'password_hash' => Hash::make(Str::password(16)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $customerId = $customer->id;
        }
        $id = DB::table('bookings')->insertGetId([
            'public_ref' => 'KC-M-'.strtoupper(Str::random(8)),
            'customer_id' => $customerId,
            'driver_id' => $driverId ?: null,
            'vehicle_id' => $vehicleId ?: null,
            'product' => strtoupper((string) ($data['bookingType'] ?? 'LOCAL_CAB')),
            'category' => strtoupper((string) ($data['vehicleType'] ?? 'SEDAN')),
            'status' => $driverId ? 'ASSIGNED' : 'REQUESTED',
            'pickup_text' => $data['pickup'] ?? $data['pickupLocation'] ?? '',
            'drop_text' => $data['drop'] ?? $data['dropLocation'] ?? '',
            'quote_paise' => (int) round(((float) ($data['fare'] ?? 0)) * 100),
            'scheduled_at' => $data['scheduledAt'] ?? null,
            'passenger_name' => $data['customerName'] ?? null,
            'passenger_phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actor, $fleet, 'booking.manual', 'booking', $id, null, ['customerId' => $customerId]);

        return $this->booking($actor, $id);
    }

    public function tracking(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicles = $this->vehicleRows($fleet);
        $onTrip = $this->activeTripDriverIds($vehicles);
        $markers = [];
        foreach ($vehicles as $row) {
            $presented = $this->presentVehicle($row, $onTrip);
            $driverUser = null;
            if ($row->driver_id) {
                $driverUser = DB::table('drivers')->where('id', $row->driver_id)->first();
            }
            $lat = $row->last_lat ?? null;
            $lng = $row->last_lng ?? null;
            if ((! $lat || ! $lng) && $driverUser) {
                $user = DB::table('users')->where('id', $driverUser->user_id)->first();
                $lat = $user->last_lat ?? null;
                $lng = $user->last_lng ?? null;
            }
            $markers[] = [
                ...$presented,
                'lat' => $lat !== null ? (float) $lat : null,
                'lng' => $lng !== null ? (float) $lng : null,
                'lastUpdatedAt' => $row->last_fix_at ?? $row->updated_at,
            ];
        }

        return ['markers' => $markers];
    }

    public function earnings(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $completed = $this->tripsQuery($fleet)->where('bookings.status', 'COMPLETED');
        $today = now()->timezone('Asia/Kolkata')->startOfDay();
        $week = now()->timezone('Asia/Kolkata')->startOfWeek();
        $month = now()->timezone('Asia/Kolkata')->startOfMonth();
        $grossToday = (int) (clone $completed)->where('bookings.updated_at', '>=', $today)->sum('bookings.quote_paise');
        $grossWeek = (int) (clone $completed)->where('bookings.updated_at', '>=', $week)->sum('bookings.quote_paise');
        $grossMonth = (int) (clone $completed)->where('bookings.updated_at', '>=', $month)->sum('bookings.quote_paise');
        $grossTotal = (int) (clone $completed)->sum('bookings.quote_paise');
        $percent = 10.0;
        if (Schema::hasTable('commission_rules')) {
            $rule = DB::table('commission_rules')->where('active', 1)->orderBy('id')->first();
            if ($rule && isset($rule->percent)) {
                $percent = (float) $rule->percent;
            }
        }
        $commissionTotal = (int) round($grossTotal * $percent / 100);

        return [
            'todayRupees' => $grossToday / 100,
            'weeklyRupees' => $grossWeek / 100,
            'monthlyRupees' => $grossMonth / 100,
            'totalRupees' => $grossTotal / 100,
            'commissionPercent' => $percent,
            'commissionRupees' => $commissionTotal / 100,
            'netRupees' => ($grossTotal - $commissionTotal) / 100,
            'pendingSettlementRupees' => ($grossTotal - $commissionTotal) / 100,
            'paidSettlementRupees' => 0,
        ];
    }

    public function reports(User $actor): array
    {
        $dash = $this->dashboard($actor);
        $earnings = $this->earnings($actor);
        $vehicles = $this->vehicles($actor)['vehicles'];
        $drivers = $this->drivers($actor)['drivers'];
        $bookings = $this->bookings($actor)['bookings'];
        $leave = $this->leaveList($actor)['leaves'];

        return [
            'kpis' => $dash['kpis'],
            'earnings' => $earnings,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'bookings' => $bookings,
            'leave' => $leave,
        ];
    }

    public function documents(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        $vehicles = $this->vehicleRows($fleet);
        $out = [];
        foreach ($vehicles as $row) {
            $out[] = [
                'vehicleId' => (int) $row->id,
                'registrationNo' => $row->registration_no,
                'documents' => $this->docs('vehicle_documents', 'vehicle_id', (int) $row->id),
            ];
        }

        return ['vehicles' => $out];
    }

    public function notifications(User $actor): array
    {
        if (! Schema::hasTable('user_notifications')) {
            return ['notifications' => []];
        }
        $rows = DB::table('user_notifications')->where('user_id', $actor->id)->orderByDesc('id')->limit(50)->get();

        return ['notifications' => $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'title' => $row->title ?? $row->subject ?? 'KarnaCab',
            'body' => $row->body ?? $row->message ?? '',
            'createdAt' => $row->created_at,
        ])->all()];
    }

    public function storeDriverDocument(User $actor, int $id, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $this->requireDriver($fleet, $id);
        $this->storeOwnedDocument('driver_documents', 'driver_id', $id, $data, 'kyc/'.$id);

        return $this->driver($actor, $id);
    }

    public function storeVehicleDocument(User $actor, int $id, array $data): array
    {
        $fleet = $this->requireFleet($actor);
        $this->requireVehicle($fleet, $id);
        $this->storeOwnedDocument('vehicle_documents', 'vehicle_id', $id, $data, 'vehicle/'.$id);

        return $this->vehicle($actor, $id);
    }

    public function profile(User $actor): array
    {
        $fleet = $this->requireFleet($actor, false);

        return [
            'id' => (string) $actor->id,
            'name' => $actor->name,
            'email' => $actor->email,
            'phone' => $actor->phone,
            'role' => $actor->role,
            'fleetName' => $fleet->trade_name ?? 'KarnaCab Fleet',
            'city' => $actor->last_address,
            'stateId' => $actor->state_id,
            'districtId' => $actor->district_id,
            'kycStatus' => $fleet->kyc_status ?? null,
            'nextStep' => FleetOnboarding::nextStep($fleet),
            'approved' => FleetOnboarding::isActive($fleet),
            'capabilities' => $this->capabilities($actor),
        ];
    }

    public function updateProfile(User $actor, array $data): array
    {
        $fleet = $this->requireFleet($actor, false);
        $userPatch = array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? $data['mobile'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($userPatch !== []) {
            $userPatch['updated_at'] = now();
            DB::table('users')->where('id', $actor->id)->update($userPatch);
        }
        if (! empty($data['fleetName'] ?? $data['trade_name'])) {
            DB::table('fleet_owners')->where('id', $fleet->id)->update([
                'trade_name' => $data['fleetName'] ?? $data['trade_name'],
                'updated_at' => now(),
            ]);
        }

        return $this->profile($actor->fresh());
    }

    public function assignments(User $actor): array
    {
        $fleet = $this->requireFleet($actor);
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            return ['assignments' => []];
        }
        $rows = DB::table('vehicle_driver_assignments')->where('fleet_owner_id', $fleet->id)->orderByDesc('id')->limit(100)->get();

        return ['assignments' => $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'vehicleId' => (int) $row->vehicle_id,
            'driverId' => (int) $row->driver_id,
            'status' => $row->status,
            'reason' => $row->reason,
            'assignedAt' => $row->assigned_at,
            'unassignedAt' => $row->unassigned_at,
        ])->all()];
    }

    private function requireFleet(User $actor, bool $mustBeActive = true): object
    {
        abort_unless(in_array($actor->role, ['FLEET_OWNER', 'ADMIN', 'SUPER_ADMIN'], true) || $this->fleetRow($actor), 403, 'Fleet Owner access only.');
        abort_if(in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true) && ! $this->fleetRow($actor), 403, 'Super Admin must use the admin panel.');
        $fleet = $this->fleetRow($actor);
        if ($fleet === null && $actor->role === 'FLEET_OWNER' && Schema::hasTable('fleet_owners')) {
            $fleet = FleetOnboarding::ensureRow((int) $actor->id);
        }
        abort_if($fleet === null, 403, 'No fleet is linked to this account.');
        if ($mustBeActive && ! FleetOnboarding::isActive($fleet)) {
            abort(403, 'Your fleet owner application is under review. Admin verification is required before you can operate.');
        }

        return $fleet;
    }

    private function fleetRow(User $actor): ?object
    {
        if (! Schema::hasTable('fleet_owners')) {
            return null;
        }

        return DB::table('fleet_owners')->where('user_id', $actor->id)->orderBy('id')->first();
    }

    private function requireVehicle(object $fleet, int $id): object
    {
        $row = DB::table('vehicles')->where('id', $id)->where('fleet_owner_id', $fleet->id)->first();
        abort_if($row === null, 404, 'Vehicle not found.');

        return $row;
    }

    private function requireDriver(object $fleet, int $id): object
    {
        $row = $this->driversQuery($fleet)
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->select('drivers.*', 'users.name', 'users.email', 'users.phone', 'users.status as account_status')
            ->where('drivers.id', $id)
            ->first();
        abort_if($row === null, 404, 'Driver not found.');

        return $row;
    }

    private function assertAssignable(object $driver): void
    {
        $kyc = strtolower((string) ($driver->kyc_status ?? ''));
        abort_unless(in_array($kyc, ['verified', 'approved', 'active'], true), 422, 'Only verified drivers can be assigned.');
        abort_if(strtolower((string) $driver->duty_status) === 'on_leave', 422, 'Driver is on leave.');
        abort_if(strtolower((string) ($driver->account_status ?? '')) === 'suspended', 422, 'Driver is suspended.');
    }

    private function vehicleRows(object $fleet): array
    {
        return DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->orderByDesc('id')->get()->all();
    }

    private function driversQuery(object $fleet)
    {
        return DB::table('drivers')->where('drivers.fleet_owner_id', $fleet->id);
    }

    private function tripsQuery(object $fleet)
    {
        $driverIds = DB::table('drivers')->where('fleet_owner_id', $fleet->id)->pluck('id');
        $vehicleIds = DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->pluck('id');

        return DB::table('bookings')
            ->leftJoin('users as customers', 'customers.id', '=', 'bookings.customer_id')
            ->select('bookings.*', 'customers.name as customer_name', 'customers.phone as customer_phone')
            ->where(function ($q) use ($driverIds, $vehicleIds) {
                $matched = false;
                if ($vehicleIds->isNotEmpty()) {
                    $q->orWhereIn('bookings.vehicle_id', $vehicleIds);
                    $matched = true;
                }
                if ($driverIds->isNotEmpty()) {
                    $q->orWhereIn('bookings.driver_id', $driverIds);
                    $matched = true;
                }
                if (! $matched) {
                    $q->whereRaw('0 = 1');
                }
            });
    }

    private function activeTripDriverIds(array $vehicles): array
    {
        $ids = array_values(array_filter(array_map(fn ($v) => (int) ($v->driver_id ?? 0), $vehicles)));
        if ($ids === [] || ! Schema::hasTable('bookings')) {
            return [];
        }

        return DB::table('bookings')
            ->whereIn('driver_id', $ids)
            ->whereIn('status', ['ACCEPTED', 'ARRIVING', 'STARTED', 'ON_TRIP', 'IN_PROGRESS'])
            ->pluck('driver_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function presentFleet(object $fleet, User $actor): array
    {
        $stateName = null;
        $districtName = null;
        $stateId = $fleet->state_id ?? $actor->state_id ?? null;
        $districtId = $fleet->district_id ?? $actor->district_id ?? null;
        if ($stateId && Schema::hasTable('states')) {
            $stateName = DB::table('states')->where('id', $stateId)->value('name');
        }
        if ($districtId && Schema::hasTable('districts')) {
            $districtName = DB::table('districts')->where('id', $districtId)->value('name');
        }

        return [
            'id' => (int) $fleet->id,
            'name' => $fleet->trade_name ?? ($actor->name.' Fleet'),
            'ownerName' => $actor->name,
            'stateId' => $stateId,
            'districtId' => $districtId,
            'franchiseId' => $fleet->franchise_id ?? null,
            'companyType' => $fleet->company_type ?? null,
            'kycStatus' => $fleet->kyc_status ?? null,
            'status' => $fleet->status ?? null,
            'approved' => FleetOnboarding::isActive($fleet),
            'stateName' => $stateName,
            'districtName' => $districtName,
        ];
    }

    private function presentVehicle(object $row, array $onTrip): array
    {
        $driverId = $row->driver_id ? (int) $row->driver_id : null;
        $status = strtolower((string) ($row->status ?? 'available'));
        if ($driverId && in_array($driverId, $onTrip, true)) {
            $status = 'on_trip';
        } elseif ($status === 'assigned' && ! $driverId) {
            $status = 'available';
        }
        $driver = $driverId ? DB::table('drivers')->leftJoin('users', 'users.id', '=', 'drivers.user_id')->where('drivers.id', $driverId)->select('drivers.id', 'users.name', 'users.phone', 'drivers.duty_status', 'drivers.online')->first() : null;

        return [
            'id' => (int) $row->id,
            'registrationNo' => $row->registration_no,
            'vehicleType' => $row->category,
            'make' => $row->brand,
            'model' => $row->model,
            'year' => $row->year,
            'color' => $row->color,
            'fuelType' => $row->fuel,
            'status' => $status,
            'pendingReview' => in_array($status, ['pending_review', 'pending'], true),
            'approved' => ! in_array($status, ['pending_review', 'pending', 'rejected'], true),
            'assignedDriverId' => $driverId,
            'assignedDriverName' => $driver->name ?? null,
            'assignedDriverPhone' => $driver->phone ?? null,
            'assignedDriverDuty' => $driver->duty_status ?? null,
        ];
    }

    private function presentDriver(object $row, object $fleet): array
    {
        $vehicle = DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->where('driver_id', $row->id)->first();
        $duty = strtolower((string) ($row->duty_status ?? 'offline'));
        $status = $duty;
        if ((int) $row->online === 1 && $duty !== 'on_leave') {
            $status = 'online';
        }

        return [
            'id' => (int) $row->id,
            'userId' => (int) $row->user_id,
            'name' => $row->name ?? 'Driver',
            'email' => $row->email ?? '',
            'phone' => $row->phone ?? '',
            'kycStatus' => $row->kyc_status,
            'dutyStatus' => $duty,
            'status' => $status,
            'online' => (bool) $row->online,
            'rating' => (float) ($row->rating_avg ?? 0),
            'licenseNo' => $row->license_no,
            'vehicleId' => $vehicle->id ?? null,
            'vehicleNo' => $vehicle->registration_no ?? null,
            'accountStatus' => $row->account_status ?? 'PENDING',
            'active' => in_array(strtolower((string) ($row->kyc_status ?? '')), ['verified', 'approved', 'active'], true)
                && strtoupper((string) ($row->account_status ?? '')) === 'ACTIVE',
        ];
    }

    private function presentTrip(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'ref' => $row->public_ref ?? ('#'.$row->id),
            'customer' => $row->customer_name ?? $row->passenger_name ?? 'Customer',
            'phone' => $row->customer_phone ?? $row->passenger_phone ?? '',
            'pickup' => $row->pickup_text ?? '',
            'drop' => $row->drop_text ?? '',
            'bookingType' => $row->product ?? '',
            'vehicleId' => $row->vehicle_id,
            'driverId' => $row->driver_id,
            'fareRupees' => ((int) ($row->quote_paise ?? 0)) / 100,
            'status' => $row->status,
            'createdAt' => $row->created_at,
            'scheduledAt' => $row->scheduled_at ?? null,
        ];
    }

    private function presentLeave(object $row, object $fleet): array
    {
        $driver = DB::table('drivers')
            ->leftJoin('users', 'users.id', '=', 'drivers.user_id')
            ->where('drivers.id', $row->driver_id)
            ->select('users.name')
            ->first();
        $vehicleNo = $row->vehicle_id
            ? DB::table('vehicles')->where('id', $row->vehicle_id)->where('fleet_owner_id', $fleet->id)->value('registration_no')
            : DB::table('vehicles')->where('fleet_owner_id', $fleet->id)->where('driver_id', $row->driver_id)->value('registration_no');

        return [
            'id' => (int) $row->id,
            'driverId' => (int) $row->driver_id,
            'vehicleId' => $row->vehicle_id,
            'driverName' => $driver->name ?? 'Driver',
            'vehicleNo' => $vehicleNo,
            'status' => $row->status,
            'reason' => $row->reason,
            'startsOn' => $row->starts_on,
            'endsOn' => $row->ends_on,
            'replacementRequired' => $row->status === 'APPROVED' && $row->vehicle_id,
        ];
    }

    private function docs(string $table, string $fk, int $id): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $warnDays = 30;
        $now = now();

        return DB::table($table)->where($fk, $id)->orderBy('id')->get()->map(function ($doc) use ($now, $warnDays) {
            $expires = $doc->expires_at ?? null;
            $label = 'VALID';
            if ($expires) {
                $at = \Carbon\Carbon::parse($expires);
                if ($at->isPast()) {
                    $label = 'EXPIRED';
                } elseif ($at->lte($now->copy()->addDays($warnDays))) {
                    $label = 'EXPIRING';
                }
            }

            return [
                'id' => (int) $doc->id,
                'type' => $doc->type,
                'status' => $doc->status,
                'expiresAt' => $expires,
                'expiryLabel' => $label,
            ];
        })->all();
    }

    private function openAssignment(object $fleet, int $vehicleId, int $driverId, int $by, string $reason): void
    {
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            return;
        }
        DB::table('vehicle_driver_assignments')->insert([
            'fleet_owner_id' => $fleet->id,
            'vehicle_id' => $vehicleId,
            'driver_id' => $driverId,
            'assigned_by' => $by,
            'status' => 'ACTIVE',
            'reason' => $reason,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function closeActiveAssignment(object $fleet, int $vehicleId, string $reason, int $by): void
    {
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            return;
        }
        DB::table('vehicle_driver_assignments')
            ->where('fleet_owner_id', $fleet->id)
            ->where('vehicle_id', $vehicleId)
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'ENDED',
                'reason' => $reason,
                'unassigned_at' => now(),
                'updated_at' => now(),
                'assigned_by' => $by,
            ]);
    }

    private function assignmentsForVehicle(object $fleet, int $vehicleId): array
    {
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            return [];
        }

        return DB::table('vehicle_driver_assignments')->where('fleet_owner_id', $fleet->id)->where('vehicle_id', $vehicleId)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'driverId' => (int) $row->driver_id,
            'status' => $row->status,
            'reason' => $row->reason,
            'assignedAt' => $row->assigned_at,
            'unassignedAt' => $row->unassigned_at,
        ])->all();
    }

    private function assignmentsForDriver(object $fleet, int $driverId): array
    {
        if (! Schema::hasTable('vehicle_driver_assignments')) {
            return [];
        }

        return DB::table('vehicle_driver_assignments')->where('fleet_owner_id', $fleet->id)->where('driver_id', $driverId)->orderByDesc('id')->limit(40)->get()->all();
    }

    private function audit(User $actor, object $fleet, string $action, string $entity, int $entityId, mixed $old, mixed $new): void
    {
        if (! Schema::hasTable('platform_audit_events')) {
            return;
        }
        try {
            DB::table('platform_audit_events')->insert([
                'user_id' => $actor->id,
                'action' => $action,
                'entity_type' => $entity,
                'entity_id' => $entityId,
                'meta' => json_encode(['fleet_id' => $fleet->id, 'old' => $old, 'new' => $new]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
        }
    }

    private function presentOnboarding(User $actor, object $fleet): array
    {
        $docs = FleetOnboarding::documents($fleet);

        return [
            'fleet' => $this->presentFleet($fleet, $actor),
            'companyType' => $fleet->company_type ?? null,
            'tradeName' => $fleet->trade_name ?? '',
            'gstin' => $fleet->gstin ?? '',
            'pan' => $fleet->pan ?? '',
            'address' => $fleet->address ?? '',
            'email' => $fleet->contact_email ?? ($actor->email ?? ''),
            'phone' => $fleet->contact_phone ?? ($actor->phone ?? ''),
            'ownerName' => $actor->name,
            'kycStatus' => $fleet->kyc_status ?? 'pending',
            'status' => $fleet->status ?? 'PENDING',
            'submittedAt' => $fleet->submitted_at ?? null,
            'nextStep' => FleetOnboarding::nextStep($fleet),
            'approved' => FleetOnboarding::isActive($fleet),
            'documents' => $docs,
            'companyTypes' => FleetOnboarding::COMPANY_TYPES,
            'requiredDocs' => FleetOnboarding::COMPANY_DOCS,
        ];
    }

    private function storeOwnedDocument(string $table, string $fk, int $id, array $data, string $folder): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $type = strtoupper(trim((string) ($data['type'] ?? '')));
        abort_unless($type !== '', 422, 'Document type is required.');
        $saved = $this->storeBinary($data, $folder);
        $existing = DB::table($table)->where($fk, $id)->where('type', $type)->value('id');
        $payload = [
            $fk => $id,
            'type' => $type,
            'status' => 'pending',
            'storage_key' => $saved['path'],
            'original_name' => $saved['name'],
            'mime' => $saved['mime'],
            'size_bytes' => $saved['bytes'],
            'updated_at' => now(),
        ];
        if (Schema::hasColumn($table, 'checksum_sha256')) {
            $payload['checksum_sha256'] = $saved['hash'];
        }
        if (! empty($data['expiresAt'] ?? $data['expires_at']) && Schema::hasColumn($table, 'expires_at')) {
            $payload['expires_at'] = $data['expiresAt'] ?? $data['expires_at'];
        }
        if ($existing) {
            DB::table($table)->where('id', $existing)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table($table)->insert($payload);
        }
    }

    /**
     * @return array{path:string,name:string,mime:string,bytes:int,hash:string}
     */
    private function storeBinary(array $data, string $folder): array
    {
        $binary = null;
        $mime = (string) ($data['mime'] ?? 'image/jpeg');
        $name = (string) ($data['originalName'] ?? 'document.jpg');
        if (! empty($data['fileBase64'])) {
            $raw = (string) $data['fileBase64'];
            if (str_contains($raw, ',')) {
                $raw = explode(',', $raw, 2)[1];
            }
            $binary = base64_decode($raw, true) ?: null;
        }
        abort_unless($binary, 422, 'Upload an image of this document');
        abort_unless(strlen($binary) < 8 * 1024 * 1024, 422, 'Image must be under 8 MB');
        $ext = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : 'jpg');
        $path = trim($folder, '/').'/'.Str::uuid().'.'.$ext;
        $full = storage_path('app/public/'.$path);
        $dir = dirname($full);
        abort_unless(is_dir($dir) || mkdir($dir, 0777, true) || is_dir($dir), 500, 'Could not save the attachment');
        abort_unless(file_put_contents($full, $binary) !== false, 500, 'Could not save the attachment');

        return [
            'path' => $path,
            'name' => substr($name, 0, 180),
            'mime' => substr($mime, 0, 80),
            'bytes' => strlen($binary),
            'hash' => hash('sha256', $binary),
        ];
    }
}
