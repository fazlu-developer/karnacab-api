<?php

namespace App\Services\Corporate;

use App\Models\User;
use App\Services\AppSurfaceService;
use App\Services\FcmPushService;
use App\Services\WalletAccountService;
use App\Support\CorporatePlanSchema;
use App\Support\DistrictLocator;
use App\Support\VehicleCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One corporate plan is many daily trips for a single car.
 * Customer pays 10% then 90% of the plan total to the platform.
 * After 100% is in, admin assigns one driver and that driver's wallet is credited for this plan id.
 */
class CorporatePlanService
{
    public function quote(array $data): array
    {
        $this->boot();
        $plan = $this->resolvePlan($data);
        $days = max(1, (int) ($plan['durationDays'] ?? 0));
        abort_if($days < 7, 422, 'Choose a 7, 15 or 30 day corporate plan.');
        $fare = app(AppSurfaceService::class)->corporateDailyFare($data);
        $vehicle = $fare['vehicle'];
        $daily = (int) ($vehicle['perCabPaise'] ?? 0) + (int) ($vehicle['driverAllowPaise'] ?? 0) + (int) ($vehicle['gstPaise'] ?? 0);
        $mode = strtoupper((string) ($plan['pricingMode'] ?? 'VEHICLE'));
        $total = $daily * $days;
        if ($mode === 'FIXED' && (int) ($plan['pricePaise'] ?? 0) > 0) {
            $total = (int) $plan['pricePaise'];
            $daily = intdiv($total, $days);
        }
        abort_if($total < 1, 422, 'No fare is published for this corporate plan.');
        $pay10 = \App\Support\AdvancePayment::paise($total, 'CORPORATE');
        $pay90 = $total - $pay10;
        $percent = $this->commissionPercent();
        $commission = (int) round($total * $percent / 100);
        $driver = max(0, $total - $commission);

        return [
            'tripKind' => $fare['tripKind'],
            'category' => strtoupper((string) ($data['category'] ?? $vehicle['category'] ?? 'SEDAN')),
            'vehicle' => $vehicle,
            'options' => $fare['options'],
            'plan' => $plan,
            'days' => $days,
            'dailyPaise' => $daily,
            'dailyRupees' => $daily / 100,
            'basePaise' => (int) ($vehicle['perCabPaise'] ?? 0) * ($mode === 'FIXED' ? 1 : $days),
            'driverAllowPaise' => (int) ($vehicle['driverAllowPaise'] ?? 0) * $days,
            'tollPaise' => 0,
            'gstPaise' => (int) ($vehicle['gstPaise'] ?? 0) * $days,
            'gstPercent' => (float) ($plan['gstPercent'] ?? $vehicle['gstPercent'] ?? 5),
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'pay10Paise' => $pay10,
            'pay10Rupees' => $pay10 / 100,
            'pay90Paise' => $pay90,
            'pay90Rupees' => $pay90 / 100,
            'commissionPercent' => $percent,
            'commissionPaise' => $commission,
            'driverSharePaise' => $driver,
            'driverShareRupees' => $driver / 100,
            'currency' => 'INR',
        ];
    }

    public function create(User $actor, array $data): array
    {
        $this->boot();
        $kind = $this->kind($data);
        $quote = $this->quote($data);
        $pickup = trim((string) ($data['pickupText'] ?? ''));
        abort_if($pickup === '' || ! isset($data['pickupLat'], $data['pickupLng']), 422, 'Pickup location is required.');
        $stops = $this->normalizeStops($data['stops'] ?? []);
        if ($kind === 'MULTI_STOP') {
            abort_if($stops === [], 422, 'Add the stops for each day.');
        } else {
            abort_if(trim((string) ($data['dropText'] ?? '')) === '' || ! isset($data['dropLat'], $data['dropLng']), 422, 'Drop location is required.');
        }
        $start = $this->startDate($data);
        $days = (int) $quote['days'];
        $end = $start->copy()->addDays($days - 1);
        $time = $this->clock($data['pickupTime'] ?? $data['eventTime'] ?? '09:00');
        $territory = $this->territory($actor, $data);
        $total = (int) $quote['totalPaise'];
        $km = is_numeric($quote['vehicle']['billedKm'] ?? null) ? (float) $quote['vehicle']['billedKm'] : null;
        $ref = 'CORP'.random_int(1000000, 9999999);
        $accountId = null;
        if (Schema::hasTable('corporate_accounts') && Schema::hasColumn('corporate_accounts', 'owner_user_id')) {
            $accountId = DB::table('corporate_accounts')->where('owner_user_id', $actor->id)->value('id');
        }
        $id = DB::transaction(function () use ($actor, $data, $quote, $kind, $stops, $start, $end, $days, $time, $territory, $total, $ref, $pickup, $accountId, $km) {
            $id = DB::table('corporate_travel_bookings')->insertGetId($this->only('corporate_travel_bookings', [
                'public_ref' => $ref,
                'customer_id' => $actor->id,
                'corporate_account_id' => $accountId,
                'plan_id' => $quote['plan']['id'] ?? null,
                'plan_key' => $quote['plan']['key'] ?? null,
                'category' => $quote['category'],
                'trip_kind' => $kind,
                'purpose' => $data['purpose'] ?? null,
                'service_key' => $data['serviceKey'] ?? null,
                'quote_paise' => $total,
                'pay10_paise' => $quote['pay10Paise'],
                'pay90_paise' => $quote['pay90Paise'],
                'paid_paise' => 0,
                'driver_share_paise' => $quote['driverSharePaise'],
                'commission_paise' => $quote['commissionPaise'],
                'commission_percent' => $quote['commissionPercent'],
                'pickup_text' => $pickup,
                'drop_text' => $data['dropText'] ?? ($stops[count($stops) - 1]['text'] ?? null),
                'pickup_lat' => $data['pickupLat'],
                'pickup_lng' => $data['pickupLng'],
                'drop_lat' => $data['dropLat'] ?? ($stops[count($stops) - 1]['lat'] ?? null),
                'drop_lng' => $data['dropLng'] ?? ($stops[count($stops) - 1]['lng'] ?? null),
                'travel_date' => $start->toDateString(),
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'duration_days' => $days,
                'pickup_time' => $time,
                'passengers' => max(1, (int) ($data['passengers'] ?? 1)),
                'requirements' => $data['requirements'] ?? null,
                'quote_snapshot' => json_encode($quote),
                'stops_json' => json_encode($stops),
                'payment_status' => 'unpaid',
                'status' => 'REQUESTED',
                'state_id' => $territory['stateId'],
                'district_id' => $territory['districtId'],
                'contact_name' => $data['contactName'] ?? $actor->name,
                'contact_phone' => $data['contactPhone'] ?? $actor->phone,
                'distance_km' => $km,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->syncTrips($this->row($id));

            return $id;
        });
        $row = $this->row($id);
        $this->notifyOpsAndHeads($row, 'Corporate plan requested', $row->public_ref.' is waiting for approval.', 'corporate_requested');
        $presented = $this->present($row, $actor);
        $presented['message'] = 'Corporate plan requested';
        $presented['ok'] = true;

        return $presented;
    }

    public function show(User $actor, string $id): array
    {
        $this->boot();

        return $this->present($this->visible($actor, $id), $actor);
    }

    public function track(User $actor, string $id): array
    {
        $this->boot();
        $row = $this->visible($actor, $id);
        $presented = $this->present($row, $actor);
        $presented['tracking'] = $presented['driver'] !== null;

        return $presented;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customerCards(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('corporate_travel_bookings')) {
            return [];
        }
        $q = DB::table('corporate_travel_bookings')->orderByDesc('id')->limit(40);
        if (in_array($actor->role, ['CUSTOMER', 'CORPORATE'], true)) {
            $q->where('customer_id', $actor->id);
        } elseif ($actor->role === 'STATE_HEAD' && Schema::hasColumn('corporate_travel_bookings', 'state_id')) {
            $q->where('state_id', $actor->state_id);
        } elseif (in_array($actor->role, ['DISTRICT_HEAD', 'FRANCHISE'], true) && Schema::hasColumn('corporate_travel_bookings', 'district_id')) {
            $q->where('district_id', $actor->district_id);
        } elseif (! in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return [];
        }

        return $q->get()->map(fn ($row) => $this->present($row, $actor))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function driverCards(User $actor): array
    {
        $this->boot();
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        if ($driverId < 1 || ! Schema::hasTable('corporate_travel_bookings')) {
            return [];
        }
        $plans = DB::table('corporate_travel_bookings')
            ->where('driver_id', $driverId)
            ->whereIn('status', ['ASSIGNED', 'LIVE', 'EXPIRED', 'COMPLETED'])
            ->orderByDesc('id')
            ->limit(20)
            ->get();
        $cards = [];
        foreach ($plans as $plan) {
            $trip = $this->driverFocusTrip($plan);
            if ($trip) {
                $cards[] = $this->driverTrip($trip, $plan);
            }
        }

        return $cards;
    }

    public function approve(int $id): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'Only a requested corporate plan can be approved.');
        DB::table('corporate_travel_bookings')->where('id', $id)->update($this->only('corporate_travel_bookings', [
            'status' => 'APPROVED',
            'approved_at' => now(),
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        $this->push([(int) $fresh->customer_id], 'Corporate plan approved', 'Pay '.\App\Support\AdvancePayment::percent('CORPORATE').'% of '.$fresh->public_ref.' to continue. The plan is not live until you pay.', 'corporate_approved');

        return $this->present($fresh);
    }

    public function reject(int $id, string $reason): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'This corporate plan can no longer be rejected.');
        $reason = trim($reason);
        abort_if($reason === '', 422, 'A reject reason is required.');
        DB::table('corporate_travel_bookings')->where('id', $id)->update($this->only('corporate_travel_bookings', [
            'status' => 'REJECTED',
            'reject_reason' => $reason,
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        $this->push([(int) $fresh->customer_id], 'Corporate plan not approved', $reason, 'corporate_rejected');

        return $this->present($fresh);
    }

    public function pay10(User $actor, string $id, array $data): array
    {
        return $this->payStage($actor, $id, $data, '10');
    }

    public function pay90(User $actor, string $id, array $data): array
    {
        return $this->payStage($actor, $id, $data, '90');
    }

    public function settlePayu(int $userId, string $planId, string $txnid, int $amountPaise, string $stage): void
    {
        $this->boot();
        if ($planId === '' || $txnid === '') {
            return;
        }
        $row = $this->row($this->numericId($planId));
        if ((int) $row->customer_id !== $userId) {
            return;
        }
        $actor = User::query()->find($userId);
        abort_unless($actor, 404);
        $this->settleStage($row, $stage === '90' ? '90' : '10', 'ONLINE', $txnid, $amountPaise, $actor);
    }

    public function assign(int $id, int $driverId): array
    {
        $this->boot();
        $row = $this->row($id);
        if ((string) $row->status === 'ASSIGNED' && (int) $row->driver_id === $driverId) {
            return $this->present($row);
        }
        abort_unless((string) $row->status === 'PAID_100', 422, 'Assign the driver after the customer has paid 100% of the plan.');
        $driver = $this->driverFor($row, $driverId);
        $this->assertFree($driverId, $row);
        $otp = $row->start_otp ?: (string) random_int(1000, 9999);
        DB::transaction(function () use ($row, $driver, $otp) {
            DB::table('corporate_travel_bookings')->where('id', $row->id)->update($this->only('corporate_travel_bookings', [
                'status' => 'ASSIGNED',
                'driver_id' => $driver['driverId'],
                'driver_user_id' => $driver['userId'],
                'vehicle_id' => $driver['vehicleId'],
                'fleet_owner_id' => $driver['fleetOwnerId'],
                'fleet_owner_user_id' => $driver['fleetUserId'],
                'driver_name' => $driver['name'],
                'driver_phone' => $driver['phone'],
                'plate' => $driver['plate'],
                'start_otp' => $otp,
                'assigned_at' => now(),
                'updated_at' => now(),
            ]));
            DB::table('corporate_daily_trips')->where('corporate_booking_id', $row->id)->where('status', 'scheduled')->update([
                'driver_id' => $driver['driverId'],
                'updated_at' => now(),
            ]);
            $fresh = $this->row((int) $row->id);
            $this->creditDriver($fresh);
            $this->postShares($fresh);
        });
        $fresh = $this->row($id);
        $this->push([(int) $fresh->customer_id], 'Corporate driver assigned', $fresh->driver_name.' will pick you up daily at '.$fresh->pickup_time.' on '.$fresh->public_ref.'.', 'corporate_assigned');
        $this->push([(int) $fresh->driver_user_id], 'Corporate plan assigned', 'Daily pickup at '.$fresh->pickup_time.' for '.$fresh->public_ref.'. The plan is prepaid. Do not collect the plan fare.', 'corporate_assigned');

        return $this->present($fresh);
    }

    public function setShares(int $id, array $data): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless(! in_array((string) $row->status, ['REQUESTED', 'REJECTED', 'CANCELLED'], true), 422, 'Approve the plan before setting commission shares.');
        $commission = (int) $row->commission_paise;
        $district = $this->sharePaise($data, 'districtHead', $commission, $row->district_head_paise);
        $fleet = $this->sharePaise($data, 'fleetOwner', $commission, $row->fleet_owner_paise);
        abort_if($district + $fleet > $commission, 422, 'District and fleet shares cannot exceed the platform commission.');
        if ($fleet > 0) {
            abort_unless((int) ($row->fleet_owner_id ?? 0) > 0 || (string) $row->status !== 'ASSIGNED', 422, 'Assign a fleet vehicle before paying a fleet share.');
            if ((string) $row->status === 'ASSIGNED') {
                abort_unless((int) ($row->fleet_owner_user_id ?? 0) > 0, 422, 'This car is not a district fleet vehicle.');
            }
        }
        if ($district > 0) {
            $head = $this->districtHeadUser($row->district_id ? (int) $row->district_id : null);
            abort_unless($head, 422, 'This district has no District Head.');
        }
        DB::table('corporate_travel_bookings')->where('id', $id)->update($this->only('corporate_travel_bookings', [
            'district_head_paise' => $district,
            'fleet_owner_paise' => $fleet,
            'district_head_user_id' => $district > 0 ? $this->districtHeadUser($row->district_id ? (int) $row->district_id : null) : null,
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        if ((string) $fresh->status === 'ASSIGNED' || (string) $fresh->status === 'LIVE' || (string) $fresh->status === 'EXPIRED' || (string) $fresh->status === 'COMPLETED') {
            $this->postShares($fresh);
            $fresh = $this->row($id);
        }

        return $this->present($fresh);
    }

    public function updateTimetable(User $actor, string $id, array $data): array
    {
        $this->boot();
        abort_unless(in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true), 403, 'Only admin can change the corporate timetable.');
        $row = $this->row($this->numericId($id));
        abort_if(in_array((string) $row->status, ['REJECTED', 'CANCELLED', 'COMPLETED'], true), 422, 'This plan can no longer be rescheduled.');
        $time = isset($data['pickupTime']) ? $this->clock($data['pickupTime']) : substr((string) $row->pickup_time, 0, 5);
        $start = isset($data['startsOn']) ? Carbon::parse((string) $data['startsOn'])->startOfDay() : Carbon::parse((string) ($row->starts_on ?: $row->travel_date))->startOfDay();
        $end = isset($data['endsOn']) ? Carbon::parse((string) $data['endsOn'])->startOfDay() : Carbon::parse((string) ($row->ends_on ?: $row->starts_on))->startOfDay();
        abort_if($end->lt($start), 422, 'The plan end date is before the start date.');
        if ((int) ($row->driver_id ?? 0) > 0) {
            $probe = clone $row;
            $probe->starts_on = $start->toDateString();
            $probe->ends_on = $end->toDateString();
            $probe->pickup_time = $time;
            $this->assertFree((int) $row->driver_id, $probe);
        }
        DB::table('corporate_travel_bookings')->where('id', $row->id)->update($this->only('corporate_travel_bookings', [
            'pickup_time' => $time,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'travel_date' => $start->toDateString(),
            'duration_days' => $start->diffInDays($end) + 1,
            'updated_at' => now(),
        ]));
        $fresh = $this->row((int) $row->id);
        $this->syncTrips($fresh);
        $this->push([(int) $fresh->customer_id, (int) ($fresh->driver_user_id ?? 0)], 'Corporate timetable updated', $fresh->public_ref.' now picks up at '.$fresh->pickup_time.' from '.$fresh->starts_on.' to '.$fresh->ends_on.'.', 'corporate_timetable');

        return $this->present($fresh);
    }

    public function renew(int $id, array $data): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_if(in_array((string) $row->status, ['REJECTED', 'CANCELLED', 'REQUESTED'], true), 422, 'Renew a plan after it has been approved.');
        $customer = User::query()->find((int) $row->customer_id);
        abort_unless($customer, 404, 'Customer not found');
        $days = (int) ($data['durationDays'] ?? $row->duration_days ?? 30);
        $planKey = ! empty($data['planKey']) || ! empty($data['planId'])
            ? (string) ($data['planKey'] ?? '')
            : match (true) {
                $days <= 7 => 'DAY_7',
                $days <= 15 => 'DAY_15',
                default => 'DAY_30',
            };
        $start = isset($data['startsOn'])
            ? Carbon::parse((string) $data['startsOn'])->startOfDay()
            : Carbon::parse((string) ($row->ends_on ?: now()->toDateString()))->addDay()->startOfDay();
        $payload = [
            'planId' => $data['planId'] ?? null,
            'planKey' => $planKey,
            'category' => $row->category,
            'tripKind' => $row->trip_kind,
            'pickupLat' => $row->pickup_lat,
            'pickupLng' => $row->pickup_lng,
            'dropLat' => $row->drop_lat,
            'dropLng' => $row->drop_lng,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupTime' => substr((string) $row->pickup_time, 0, 5),
            'travelDate' => $start->toDateString(),
            'passengers' => $row->passengers,
            'stops' => $this->stops($row),
            'contactName' => $row->contact_name,
            'contactPhone' => $row->contact_phone,
            'serviceKey' => $row->service_key,
            'purpose' => $row->purpose,
            'planKey' => $planKey,
            'planId' => $data['planId'] ?? null,
        ];
        $created = $this->create($customer, $payload);
        $newId = (int) $created['planId'];
        DB::table('corporate_travel_bookings')->where('id', $newId)->update($this->only('corporate_travel_bookings', [
            'renewed_from_id' => $row->id,
            'state_id' => $row->state_id,
            'district_id' => $row->district_id,
            'updated_at' => now(),
        ]));
        $comped = ! empty($data['comped']);
        if ($comped) {
            $fresh = $this->row($newId);
            DB::table('corporate_travel_bookings')->where('id', $newId)->update($this->only('corporate_travel_bookings', [
                'status' => 'PAID_100',
                'comped' => true,
                'payment_status' => 'paid',
                'payment_method' => 'COMPED',
                'paid_paise' => (int) $fresh->quote_paise,
                'invoice_no' => 'CINV'.strtoupper(Str::random(8)),
                'approved_at' => now(),
                'paid_full_at' => now(),
                'updated_at' => now(),
            ]));
        }

        return $this->present($this->row($newId));
    }

    public function requestRenew(User $actor, string $id): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        DB::table('corporate_travel_bookings')->where('id', $row->id)->update($this->only('corporate_travel_bookings', [
            'renew_requested_at' => now(),
            'updated_at' => now(),
        ]));
        $fresh = $this->row((int) $row->id);
        $this->notifyOpsAndHeads($fresh, 'Corporate renew requested', $fresh->public_ref.' asked to renew.', 'corporate_renew_requested');
        $presented = $this->present($fresh, $actor);
        $presented['renewAvailable'] = false;

        return $presented;
    }

    public function activate(User $actor, string $tripId, string $otp): array
    {
        $this->boot();
        $trip = $this->driverTripRow($actor, $tripId);
        $plan = $this->row((int) $trip->corporate_booking_id);
        abort_unless(in_array((string) $plan->status, ['ASSIGNED', 'LIVE'], true), 422, 'This corporate plan is not ready to start.');
        abort_unless($this->isToday($trip->trip_date), 422, 'This corporate pickup is not today\'s ride.');
        if ((string) $trip->phase !== 'idle') {
            return $this->driverTrip($trip, $plan);
        }
        abort_unless(hash_equals((string) $plan->start_otp, trim($otp)), 422, 'That start OTP does not match.');
        $gps = $this->driverGps((int) $plan->driver_user_id, $plan);
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'status' => 'live',
            'phase' => 'activated',
            'lat' => $gps['lat'],
            'lng' => $gps['lng'],
            'activated_at' => now(),
            'updated_at' => now(),
        ]);
        if ((string) $plan->status === 'ASSIGNED') {
            DB::table('corporate_travel_bookings')->where('id', $plan->id)->update($this->only('corporate_travel_bookings', [
                'status' => 'LIVE',
                'updated_at' => now(),
            ]));
        }
        $this->push([(int) $plan->customer_id], 'Corporate cab started', 'Today\'s car on '.$plan->public_ref.' is on the map.', 'corporate_started');

        return $this->driverTrip($this->trip((int) $trip->id), $this->row((int) $plan->id));
    }

    public function location(User $actor, string $tripId, float $lat, float $lng): array
    {
        $this->boot();
        $trip = $this->driverTripRow($actor, $tripId);
        abort_if((string) $trip->phase === 'idle', 422, 'Activate today\'s corporate trip before sending location.');
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'lat' => $lat,
            'lng' => $lng,
            'updated_at' => now(),
        ]);
        if (Schema::hasColumn('users', 'last_lat')) {
            DB::table('users')->where('id', $actor->id)->update(['last_lat' => $lat, 'last_lng' => $lng, 'updated_at' => now()]);
        }

        return ['ok' => true, 'tripId' => (string) $trip->id];
    }

    public function depart(User $actor, string $tripId): array
    {
        $trip = $this->guardPhase($actor, $tripId, 'activated');
        $plan = $this->row((int) $trip->corporate_booking_id);
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'phase' => 'outbound',
            'status' => 'live',
            'departed_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->driverTrip($this->trip((int) $trip->id), $plan);
    }

    public function completeOutbound(User $actor, string $tripId): array
    {
        $trip = $this->guardPhase($actor, $tripId, 'outbound');
        $plan = $this->row((int) $trip->corporate_booking_id);
        abort_unless($this->kindOf($plan) === 'ROUND_WAY', 422, 'Waiting is only for a round trip.');
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'phase' => 'waiting',
            'wait_started_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->driverTrip($this->trip((int) $trip->id), $plan);
    }

    public function startReturn(User $actor, string $tripId): array
    {
        $trip = $this->guardPhase($actor, $tripId, 'waiting');
        $plan = $this->row((int) $trip->corporate_booking_id);
        abort_unless($this->kindOf($plan) === 'ROUND_WAY', 422, 'Return is only for a round trip.');
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'phase' => 'return',
            'return_started_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->driverTrip($this->trip((int) $trip->id), $plan);
    }

    public function completeStop(User $actor, string $tripId): array
    {
        $trip = $this->guardPhase($actor, $tripId, 'outbound');
        $plan = $this->row((int) $trip->corporate_booking_id);
        abort_unless($this->kindOf($plan) === 'MULTI_STOP', 422, 'Stops belong to a multi-stop corporate day.');
        $stops = $this->stops($plan);
        $done = (int) $trip->stop_index + 1;
        abort_if($done > count($stops), 422, 'Every stop today is already complete.');
        if ($done >= count($stops)) {
            return $this->finishDay($trip, $plan);
        }
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'stop_index' => $done,
            'phase' => 'outbound',
            'updated_at' => now(),
        ]);

        return $this->driverTrip($this->trip((int) $trip->id), $plan);
    }

    public function completeDay(User $actor, string $tripId): array
    {
        $trip = $this->driverTripRow($actor, $tripId);
        $plan = $this->row((int) $trip->corporate_booking_id);
        $kind = $this->kindOf($plan);
        if ($kind === 'ROUND_WAY') {
            abort_unless((string) $trip->phase === 'return', 422, 'Start the return before completing today. The day stays open after the outbound leg.');
        } elseif ($kind === 'MULTI_STOP') {
            abort(422, 'Complete each stop. Today finishes on the last stop.');
        } else {
            abort_unless((string) $trip->phase === 'outbound', 422, 'Leave pickup before completing today.');
        }

        return $this->finishDay($trip, $plan);
    }

    public function skip(User $actor, string $tripId, string $reason): array
    {
        $this->boot();
        $trip = $this->trip($this->numericId($tripId));
        $plan = $this->row((int) $trip->corporate_booking_id);
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        $admin = in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true);
        abort_unless($admin || ($driverId > 0 && $driverId === (int) $plan->driver_id), 403, 'Only the assigned driver or admin can skip a day.');
        abort_unless(in_array((string) $trip->status, ['scheduled', 'live'], true) && (string) $trip->phase !== 'completed', 422, 'This day is already closed.');
        $reason = trim($reason);
        abort_if($reason === '', 422, 'A skip reason is required.');
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'status' => 'skipped',
            'phase' => 'skipped',
            'skip_reason' => $reason,
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->maybeClosePlan($plan);
        $this->push([(int) $plan->customer_id], 'Corporate day skipped', $trip->trip_date.' on '.$plan->public_ref.' was marked skipped. The plan stays prepaid.', 'corporate_skipped');
        $fresh = $this->row((int) $plan->id);
        if ($actor->role === 'DRIVER') {
            return $this->driverTrip($this->trip((int) $trip->id), $fresh);
        }

        return $this->present($fresh, $actor);
    }

    public function remindDue(): int
    {
        $this->boot();
        if (! Schema::hasTable('corporate_daily_trips')) {
            return 0;
        }
        $today = Carbon::now('Asia/Kolkata')->toDateString();
        $nowMinutes = ((int) Carbon::now('Asia/Kolkata')->format('H')) * 60 + (int) Carbon::now('Asia/Kolkata')->format('i');
        $sent = 0;
        $trips = DB::table('corporate_daily_trips')->where('trip_date', $today)->where('status', 'scheduled')->whereNull('reminded_at')->limit(200)->get();
        foreach ($trips as $trip) {
            $plan = DB::table('corporate_travel_bookings')->where('id', $trip->corporate_booking_id)->first();
            if (! $plan || ! in_array((string) $plan->status, ['ASSIGNED', 'LIVE'], true)) {
                continue;
            }
            $at = $this->minutes(substr((string) $trip->pickup_time, 0, 5));
            if ($at - $nowMinutes > 60 || $at - $nowMinutes < 0) {
                continue;
            }
            DB::table('corporate_daily_trips')->where('id', $trip->id)->update(['reminded_at' => now(), 'updated_at' => now()]);
            $this->push([(int) $plan->customer_id, (int) ($plan->driver_user_id ?? 0)], 'Corporate pickup soon', 'Pickup at '.$trip->pickup_time.' for '.$plan->public_ref.'.', 'corporate_reminder');
            $sent++;
        }
        $soon = Carbon::now('Asia/Kolkata')->addDays(3)->toDateString();
        $expiring = DB::table('corporate_travel_bookings')
            ->whereIn('status', ['ASSIGNED', 'LIVE', 'PAID_100'])
            ->whereDate('ends_on', '<=', $soon)
            ->whereDate('ends_on', '>=', $today)
            ->whereNull('expiry_notified_at')
            ->limit(100)
            ->get();
        foreach ($expiring as $plan) {
            DB::table('corporate_travel_bookings')->where('id', $plan->id)->update($this->only('corporate_travel_bookings', [
                'expiry_notified_at' => now(),
                'updated_at' => now(),
            ]));
            $this->push([(int) $plan->customer_id], 'Corporate plan expiring', $plan->public_ref.' ends on '.$plan->ends_on.'. Ask admin to renew.', 'corporate_expiring');
            $sent++;
        }
        $expired = DB::table('corporate_travel_bookings')
            ->whereIn('status', ['ASSIGNED', 'LIVE', 'PAID_100', 'APPROVED_10', 'APPROVED'])
            ->whereDate('ends_on', '<', $today)
            ->limit(100)
            ->get();
        foreach ($expired as $plan) {
            $live = DB::table('corporate_daily_trips')->where('corporate_booking_id', $plan->id)->where('status', 'live')->exists();
            if ($live) {
                continue;
            }
            DB::table('corporate_travel_bookings')->where('id', $plan->id)->update($this->only('corporate_travel_bookings', [
                'status' => 'EXPIRED',
                'updated_at' => now(),
            ]));
            $this->push([(int) $plan->customer_id], 'Corporate plan expired', $plan->public_ref.' ended on '.$plan->ends_on.'.', 'corporate_expired');
            $sent++;
        }

        return $sent;
    }

    private function payStage(User $actor, string $id, array $data, string $stage): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        $method = strtoupper((string) ($data['method'] ?? $data['paymentMethod'] ?? 'ONLINE'));
        abort_if(str_contains($method, 'CASH'), 422, 'Cash is not accepted for a corporate plan. Pay online or with wallet.');
        $expected = $stage === '90' ? 'APPROVED_10' : 'APPROVED';
        $already = $stage === '90'
            ? ['PAID_100', 'ASSIGNED', 'LIVE', 'EXPIRED', 'COMPLETED']
            : ['APPROVED_10', 'PAID_100', 'ASSIGNED', 'LIVE', 'EXPIRED', 'COMPLETED'];
        if (in_array((string) $row->status, $already, true)) {
            return $this->present($row, $actor);
        }
        abort_unless((string) $row->status === $expected, 422, $stage === '90' ? 'Pay the remaining balance after the '.\App\Support\AdvancePayment::percent('CORPORATE').'% is in.' : 'The '.\App\Support\AdvancePayment::percent('CORPORATE').'% is due after admin approves the plan.');
        $amount = $stage === '90' ? (int) $row->pay90_paise : (int) $row->pay10_paise;
        if (str_contains($method, 'WALLET')) {
            $fresh = $this->settleStage($row, $stage, 'WALLET', 'CORP'.$row->id.'-PAY'.$stage, $amount, $actor);

            return $this->present($fresh, $actor);
        }
        $payu = app(\App\Services\PayUService::class)->startCorporatePayment($actor, $amount / 100, (int) $row->id, $stage);

        return [
            'booking' => $this->present($row, $actor),
            'payment' => $payu,
            'needsPlanPay10' => $stage === '10',
            'needsPlanPay90' => $stage === '90',
        ];
    }

    private function settleStage(object $row, string $stage, string $method, string $paymentRef, int $amountPaise, User $actor): object
    {
        return DB::transaction(function () use ($row, $stage, $method, $paymentRef, $amountPaise, $actor) {
            $locked = DB::table('corporate_travel_bookings')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($locked, 404);
            $expected = $stage === '90' ? 'APPROVED_10' : 'APPROVED';
            $due = $stage === '90' ? (int) $locked->pay90_paise : (int) $locked->pay10_paise;
            if ((string) $locked->status !== $expected) {
                return $locked;
            }
            abort_unless($amountPaise === $due, 422, 'Payment amount does not match this corporate plan.');
            if ($method === 'WALLET') {
                $this->move((int) $actor->id, 'CUSTOMER', 'DEBIT', $due, $paymentRef.'-CUSTOMER', 'Corporate plan '.$stage.'% '.$locked->public_ref, (int) $locked->id, (int) $locked->quote_paise);
            }
            $this->move($this->platformUserId(), 'PLATFORM', 'CREDIT', $due, $paymentRef, 'Corporate plan '.$stage.'% collected '.$locked->public_ref, (int) $locked->id, (int) $locked->quote_paise);
            $paid = (int) $locked->paid_paise + $due;
            $full = $stage === '90';
            DB::table('corporate_travel_bookings')->where('id', $locked->id)->update($this->only('corporate_travel_bookings', [
                'status' => $full ? 'PAID_100' : 'APPROVED_10',
                'payment_status' => $full ? 'paid' : 'pay10',
                'payment_method' => $method,
                'paid_paise' => $paid,
                'invoice_no' => $full ? ($locked->invoice_no ?: ('CINV'.strtoupper(Str::random(8)))) : $locked->invoice_no,
                'paid_full_at' => $full ? now() : null,
                'updated_at' => now(),
            ]));
            $fresh = $this->row((int) $locked->id);
            if ($full) {
                $this->push([(int) $fresh->customer_id], 'Corporate plan paid', 'Invoice '.$fresh->invoice_no.' for '.$fresh->public_ref.'. KarnaRide has the full plan amount.', 'corporate_paid');
                $this->notifyOpsAndHeads($fresh, 'Corporate plan paid in full', 'Assign one driver for '.$fresh->public_ref.'.', 'corporate_paid');
            } else {
                $this->push([(int) $fresh->customer_id], \App\Support\AdvancePayment::percent('CORPORATE').'% received', 'Pay the remaining balance of '.$fresh->public_ref.'.', 'corporate_pay10');
            }

            return $fresh;
        });
    }

    private function creditDriver(object $row): void
    {
        $amount = (int) $row->driver_share_paise;
        $ref = 'CORP'.$row->id.'-DRIVER';
        $this->move((int) $row->driver_user_id, 'DRIVER', 'CREDIT', $amount, $ref, 'Corporate plan '.$row->public_ref.' driver share. Plan id '.$row->id.'. Prepaid, withdraw later.', (int) $row->id, (int) $row->quote_paise);
        $this->move($this->platformUserId(), 'PLATFORM', 'DEBIT', $amount, $ref.'-OUT', 'Driver share for corporate plan '.$row->id, (int) $row->id, (int) $row->quote_paise);
    }

    private function postShares(object $row): void
    {
        $district = (int) ($row->district_head_paise ?? 0);
        $fleet = (int) ($row->fleet_owner_paise ?? 0);
        if ($district < 1 && $fleet < 1) {
            return;
        }
        abort_if($district + $fleet > (int) $row->commission_paise, 422, 'District and fleet shares cannot exceed the platform commission.');
        if ($district > 0) {
            $head = (int) ($row->district_head_user_id ?: $this->districtHeadUser($row->district_id ? (int) $row->district_id : null));
            abort_unless($head > 0, 422, 'This district has no District Head.');
            $ref = 'CORP'.$row->id.'-DISTRICT';
            $this->move($head, 'DISTRICT_HEAD', 'CREDIT', $district, $ref, 'District share of corporate plan '.$row->id, (int) $row->id, (int) $row->commission_paise);
            $this->move($this->platformUserId(), 'PLATFORM', 'DEBIT', $district, $ref.'-OUT', 'District share of corporate plan '.$row->id, (int) $row->id, (int) $row->commission_paise);
        }
        if ($fleet > 0) {
            $owner = (int) ($row->fleet_owner_user_id ?? 0);
            abort_unless($owner > 0, 422, 'This car is not a district fleet vehicle.');
            $ref = 'CORP'.$row->id.'-FLEET';
            $this->move($owner, 'FLEET_OWNER', 'CREDIT', $fleet, $ref, 'Fleet share of corporate plan '.$row->id, (int) $row->id, (int) $row->commission_paise);
            $this->move($this->platformUserId(), 'PLATFORM', 'DEBIT', $fleet, $ref.'-OUT', 'Fleet share of corporate plan '.$row->id, (int) $row->id, (int) $row->commission_paise);
        }
        DB::table('corporate_travel_bookings')->where('id', $row->id)->update($this->only('corporate_travel_bookings', [
            'shares_posted_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function move(int $userId, string $ownerType, string $direction, int $amount, string $ref, string $note, int $planId, int $gross): void
    {
        if ($amount < 1 || $userId < 1 || $this->ledgerExists($ref)) {
            return;
        }
        $wallets = app(WalletAccountService::class);
        if ($direction === 'CREDIT') {
            $aliases = match ($ownerType) {
                'PLATFORM' => ['PLATFORM', 'ADMIN'],
                'FLEET_OWNER' => ['FLEET_OWNER', 'FLEET'],
                'DISTRICT_HEAD' => ['DISTRICT_HEAD'],
                default => [$ownerType],
            };
            $wallets->creditAccount($userId, $ownerType, $planId, $amount, $gross, 'corporate_plan', $note, $aliases, $ref);

            return;
        }
        $wallet = $wallets->ensureWallet($userId, $ownerType);
        abort_unless($wallet, 422, 'Wallet not found');
        $before = (int) $wallet->balance_paise;
        abort_unless($before >= $amount, 422, $ownerType === 'CUSTOMER' ? 'Insufficient wallet balance' : 'Platform wallet cannot cover this corporate payout.');
        $after = $before - $amount;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        $wallets->ledger($wallet, $userId, $ownerType, 'DEBIT', $amount, $before, $after, 'corporate_plan', $note, $planId, 0, $gross, $ref);
    }

    /**
     * @return array{driverId: int, userId: int, vehicleId: ?int, fleetOwnerId: ?int, fleetUserId: ?int, name: string, phone: string, plate: string}
     */
    private function driverFor(object $plan, int $driverId): array
    {
        $driver = DB::table('drivers')->where('id', $driverId)->first();
        abort_unless($driver, 422, 'Choose a driver from the list.');
        $user = DB::table('users')->where('id', $driver->user_id)->first();
        abort_unless($user, 422, 'That driver has no login.');
        $vehicles = Schema::hasTable('vehicles') ? DB::table('vehicles')->where('driver_id', $driverId)->get() : collect();
        $match = $vehicles->first(fn ($vehicle) => VehicleCategory::sameFamily((string) ($vehicle->category ?? ''), (string) $plan->category));
        abort_unless($match, 422, trim(($user->name ?? 'Driver').' is not in the '.$plan->category.' category.'));
        $fleetId = (int) ($match->fleet_owner_id ?? $driver->fleet_owner_id ?? 0);
        $fleetUser = null;
        if ($fleetId > 0 && Schema::hasTable('fleet_owners')) {
            $fleetUser = (int) (DB::table('fleet_owners')->where('id', $fleetId)->value('user_id') ?? 0);
        }

        return [
            'driverId' => $driverId,
            'userId' => (int) $user->id,
            'vehicleId' => isset($match->id) ? (int) $match->id : null,
            'fleetOwnerId' => $fleetId > 0 ? $fleetId : null,
            'fleetUserId' => $fleetUser ?: null,
            'name' => (string) ($user->name ?? 'Driver'),
            'phone' => (string) ($user->phone ?? ''),
            'plate' => (string) ($match->registration_no ?? ''),
        ];
    }

    private function assertFree(int $driverId, object $plan): void
    {
        $start = (string) ($plan->starts_on ?: $plan->travel_date);
        $end = (string) ($plan->ends_on ?: $start);
        $time = substr((string) $plan->pickup_time, 0, 5);
        $hit = DB::table('corporate_travel_bookings')
            ->where('driver_id', $driverId)
            ->where('id', '!=', $plan->id)
            ->whereIn('status', ['ASSIGNED', 'LIVE', 'PAID_100'])
            ->get(['public_ref', 'starts_on', 'ends_on', 'pickup_time', 'travel_date']);
        foreach ($hit as $row) {
            $otherStart = (string) ($row->starts_on ?: $row->travel_date);
            $otherEnd = (string) ($row->ends_on ?: $otherStart);
            $overlaps = $start <= $otherEnd && $end >= $otherStart;
            if ($overlaps && $this->timesOverlap($time, substr((string) $row->pickup_time, 0, 5))) {
                abort(422, 'This driver already has a corporate plan at that daily time.');
            }
        }
    }

    private function finishDay(object $trip, object $plan): array
    {
        DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
            'status' => 'completed',
            'phase' => 'completed',
            'completed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->maybeClosePlan($plan);
        $this->push([(int) $plan->customer_id], 'Today\'s corporate ride is done', $trip->trip_date.' on '.$plan->public_ref.' is complete.', 'corporate_day_done');
        $freshPlan = $this->row((int) $plan->id);
        if ((string) $freshPlan->status === 'LIVE') {
            DB::table('corporate_travel_bookings')->where('id', $plan->id)->update($this->only('corporate_travel_bookings', [
                'status' => 'ASSIGNED',
                'updated_at' => now(),
            ]));
        }

        return $this->driverTrip($this->trip((int) $trip->id), $this->row((int) $plan->id));
    }

    private function maybeClosePlan(object $plan): void
    {
        $open = DB::table('corporate_daily_trips')->where('corporate_booking_id', $plan->id)->whereNotIn('status', ['completed', 'skipped'])->count();
        if ($open === 0) {
            DB::table('corporate_travel_bookings')->where('id', $plan->id)->update($this->only('corporate_travel_bookings', [
                'status' => 'COMPLETED',
                'updated_at' => now(),
            ]));
        }
    }

    private function syncTrips(object $plan): void
    {
        $dates = $this->dates($plan);
        $existing = DB::table('corporate_daily_trips')->where('corporate_booking_id', $plan->id)->get();
        $byDate = [];
        foreach ($existing as $trip) {
            $byDate[(string) $trip->trip_date] = $trip;
        }
        foreach ($byDate as $date => $trip) {
            if (! in_array($date, $dates, true)) {
                if ((string) $trip->status === 'scheduled' && (string) $trip->phase === 'idle') {
                    DB::table('corporate_daily_trips')->where('id', $trip->id)->delete();
                }
                continue;
            }
            if ((string) $trip->status === 'scheduled' && (string) $trip->phase === 'idle') {
                DB::table('corporate_daily_trips')->where('id', $trip->id)->update([
                    'pickup_time' => substr((string) $plan->pickup_time, 0, 5),
                    'driver_id' => $plan->driver_id,
                    'updated_at' => now(),
                ]);
            }
        }
        foreach ($dates as $date) {
            if (isset($byDate[$date])) {
                continue;
            }
            DB::table('corporate_daily_trips')->insert([
                'corporate_booking_id' => $plan->id,
                'trip_date' => $date,
                'pickup_time' => substr((string) $plan->pickup_time, 0, 5),
                'status' => 'scheduled',
                'phase' => 'idle',
                'driver_id' => $plan->driver_id,
                'stop_index' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function dates(object $plan): array
    {
        $start = Carbon::parse((string) ($plan->starts_on ?: $plan->travel_date))->startOfDay();
        $end = Carbon::parse((string) ($plan->ends_on ?: $plan->starts_on ?: $plan->travel_date))->startOfDay();
        $dates = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    private function present(object $row, ?User $viewer = null): array
    {
        $this->markExpired($row);
        $row = $this->row((int) $row->id);
        $status = (string) ($row->status ?? 'REQUESTED');
        $today = Carbon::now('Asia/Kolkata')->startOfDay();
        $ends = Carbon::parse((string) ($row->ends_on ?: $row->starts_on ?: $today->toDateString()))->startOfDay();
        $daysLeft = (int) $today->diffInDays($ends, false);
        if ($daysLeft < 0) {
            $daysLeft = 0;
        }
        $expired = $ends->lt($today) || $status === 'EXPIRED';
        $trips = DB::table('corporate_daily_trips')->where('corporate_booking_id', $row->id)->orderBy('trip_date')->get();
        $todayKey = $today->toDateString();
        $todayTrip = $trips->first(fn ($trip) => (string) $trip->trip_date === $todayKey);
        $history = [];
        foreach ($trips as $trip) {
            $history[] = [
                'id' => (string) $trip->id,
                'date' => (string) $trip->trip_date,
                'time' => substr((string) $trip->pickup_time, 0, 5),
                'status' => (string) $trip->status,
                'phase' => (string) $trip->phase,
                'skipReason' => $trip->skip_reason,
            ];
        }
        $showOtp = $row->start_otp && in_array($status, ['ASSIGNED', 'LIVE', 'EXPIRED', 'COMPLETED'], true) && ($viewer === null || $viewer->role !== 'DRIVER');
        $driverUser = $row->driver_user_id ? DB::table('users')->where('id', $row->driver_user_id)->first() : null;
        $gps = $todayTrip && $todayTrip->lat !== null
            ? ['lat' => (float) $todayTrip->lat, 'lng' => (float) $todayTrip->lng]
            : ($driverUser && $driverUser->last_lat !== null ? ['lat' => (float) $driverUser->last_lat, 'lng' => (float) $driverUser->last_lng] : ['lat' => null, 'lng' => null]);
        $total = (int) ($row->quote_paise ?? 0);
        $paid = (int) ($row->paid_paise ?? 0);
        $driver = $row->driver_name ? [
            'id' => (string) $row->driver_id,
            'name' => $row->driver_name,
            'phone' => $row->driver_phone,
            'plate' => $row->plate,
            'vehicleId' => $row->vehicle_id ? (string) $row->vehicle_id : null,
            'fleetOwnerId' => $row->fleet_owner_id ? (string) $row->fleet_owner_id : null,
            'lat' => $gps['lat'],
            'lng' => $gps['lng'],
        ] : null;
        $daily = null;
        if ($todayTrip) {
            $daily = $this->dayCard($todayTrip, $row, $driver);
        }

        return [
            'id' => 'corporate-'.$row->id,
            'planId' => (string) $row->id,
            'ok' => true,
            'kind' => 'corporate',
            'product' => 'CORPORATE',
            'publicRef' => $row->public_ref,
            'status' => $status,
            'statusLabel' => $this->label($status, $expired, $daysLeft),
            'lifecycle' => $this->lifecycle($status),
            'tripBucket' => in_array($status, ['COMPLETED', 'CANCELLED', 'REJECTED', 'EXPIRED'], true) ? 'history' : 'upcoming',
            'tripKind' => $row->trip_kind ?? 'ONE_WAY',
            'tripKindLabel' => $this->kindLabel((string) ($row->trip_kind ?? 'ONE_WAY')),
            'category' => $row->category,
            'planKey' => $row->plan_key,
            'planTitle' => $this->planTitle($row),
            'passengers' => $row->passengers,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
            'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
            'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
            'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
            'stops' => $this->stops($row),
            'startsOn' => $row->starts_on,
            'endsOn' => $row->ends_on,
            'expiresOn' => $row->ends_on,
            'days' => (int) ($row->duration_days ?? $trips->count()),
            'daysLeft' => $expired ? 0 : $daysLeft,
            'expired' => $expired,
            'expiringSoon' => ! $expired && $daysLeft <= 3 && in_array($status, ['ASSIGNED', 'LIVE', 'PAID_100', 'APPROVED_10'], true),
            'pickupTime' => substr((string) $row->pickup_time, 0, 5),
            'scheduledAt' => ($todayTrip ? $todayTrip->trip_date : $row->starts_on).'T'.substr((string) $row->pickup_time, 0, 5).':00+05:30',
            'needsPlanPay10' => $status === 'APPROVED',
            'needsPlanPay90' => $status === 'APPROVED_10',
            'renewAvailable' => false,
            'renewRequested' => ! empty($row->renew_requested_at),
            'renewedFromId' => $row->renewed_from_id ? (string) $row->renewed_from_id : null,
            'invoiceNo' => $row->invoice_no,
            'startOtp' => $showOtp ? (string) $row->start_otp : null,
            'stateId' => $row->state_id ? (int) $row->state_id : null,
            'districtId' => $row->district_id ? (int) $row->district_id : null,
            'contactName' => $row->contact_name,
            'contactPhone' => $row->contact_phone,
            'driver' => $driver,
            'dailyTrip' => $daily,
            'history' => $history,
            'tracking' => $driver !== null && $todayTrip && ! in_array((string) $todayTrip->status, ['completed', 'skipped'], true),
            'prepaid' => true,
            'collectRupees' => 0,
            'canEditTimetable' => false,
            'fare' => [
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'pay10Paise' => (int) ($row->pay10_paise ?? 0),
                'pay10Rupees' => ((int) ($row->pay10_paise ?? 0)) / 100,
                'pay90Paise' => (int) ($row->pay90_paise ?? 0),
                'pay90Rupees' => ((int) ($row->pay90_paise ?? 0)) / 100,
                'paidPaise' => $paid,
                'paidRupees' => $paid / 100,
                'driverSharePaise' => (int) ($row->driver_share_paise ?? 0),
                'driverShareRupees' => ((int) ($row->driver_share_paise ?? 0)) / 100,
                'commissionPaise' => (int) ($row->commission_paise ?? 0),
                'commissionRupees' => ((int) ($row->commission_paise ?? 0)) / 100,
                'districtHeadPaise' => $row->district_head_paise !== null ? (int) $row->district_head_paise : null,
                'fleetOwnerPaise' => $row->fleet_owner_paise !== null ? (int) $row->fleet_owner_paise : null,
                'currency' => 'INR',
            ],
            'allowedActions' => $this->actions($status, $showOtp, (bool) $row->invoice_no, $driver !== null),
            'quoteRupees' => $total / 100,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $driver
     * @return array<string, mixed>
     */
    private function dayCard(object $trip, object $plan, ?array $driver): array
    {
        $target = $this->target($trip, $plan);

        return [
            'id' => (string) $trip->id,
            'date' => (string) $trip->trip_date,
            'time' => substr((string) $trip->pickup_time, 0, 5),
            'status' => (string) $trip->status,
            'phase' => (string) $trip->phase,
            'stopIndex' => (int) $trip->stop_index,
            'targetLabel' => $target['label'],
            'targetLat' => $target['lat'],
            'targetLng' => $target['lng'],
            'lat' => $driver['lat'] ?? ($trip->lat !== null ? (float) $trip->lat : null),
            'lng' => $driver['lng'] ?? ($trip->lng !== null ? (float) $trip->lng : null),
            'showOnMap' => $driver !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverTrip(object $trip, object $plan): array
    {
        $target = $this->target($trip, $plan);
        $stops = $this->stops($plan);
        $upcoming = DB::table('corporate_daily_trips')
            ->where('corporate_booking_id', $plan->id)
            ->where('trip_date', '>=', Carbon::now('Asia/Kolkata')->toDateString())
            ->where('status', 'scheduled')
            ->orderBy('trip_date')
            ->limit(14)
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->trip_date, 'time' => substr((string) $row->pickup_time, 0, 5)])
            ->all();

        return [
            'id' => 'corporate-trip-'.$trip->id,
            'tripId' => (string) $trip->id,
            'planId' => (string) $plan->id,
            'kind' => 'corporate',
            'product' => 'CORPORATE',
            'title' => 'Corporate pickup',
            'publicRef' => $plan->public_ref,
            'status' => (string) $trip->status,
            'statusLabel' => $this->phaseLabel((string) $trip->phase, (string) $trip->status),
            'phase' => (string) $trip->phase,
            'tripKind' => $plan->trip_kind,
            'tripKindLabel' => $this->kindLabel((string) $plan->trip_kind),
            'category' => $plan->category,
            'pickupText' => $plan->pickup_text,
            'dropText' => $plan->drop_text,
            'pickupLat' => $plan->pickup_lat !== null ? (float) $plan->pickup_lat : null,
            'pickupLng' => $plan->pickup_lng !== null ? (float) $plan->pickup_lng : null,
            'dropLat' => $plan->drop_lat !== null ? (float) $plan->drop_lat : null,
            'dropLng' => $plan->drop_lng !== null ? (float) $plan->drop_lng : null,
            'stops' => $stops,
            'stopIndex' => (int) $trip->stop_index,
            'eventDate' => (string) $trip->trip_date,
            'eventTime' => substr((string) $trip->pickup_time, 0, 5),
            'scheduledAt' => $trip->trip_date.'T'.substr((string) $trip->pickup_time, 0, 5).':00+05:30',
            'customerPhone' => $plan->contact_phone,
            'customerName' => $plan->contact_name,
            'targetLabel' => $target['label'],
            'targetLat' => $target['lat'],
            'targetLng' => $target['lng'],
            'collectRupees' => 0,
            'prepaid' => true,
            'canCancel' => false,
            'schedule' => $upcoming,
            'actions' => $this->driverActions((string) $trip->phase, (string) $plan->trip_kind),
            'walletKind' => 'corporate_plan',
            'bookingId' => (string) $plan->id,
        ];
    }

    /**
     * @return array{label: string, lat: ?float, lng: ?float}
     */
    private function target(object $trip, object $plan): array
    {
        $phase = (string) $trip->phase;
        $stops = $this->stops($plan);
        if ($phase === 'idle' || $phase === 'activated') {
            return ['label' => 'Pickup', 'lat' => $plan->pickup_lat !== null ? (float) $plan->pickup_lat : null, 'lng' => $plan->pickup_lng !== null ? (float) $plan->pickup_lng : null];
        }
        if ($this->kindOf($plan) === 'MULTI_STOP') {
            $stop = $stops[(int) $trip->stop_index] ?? null;
            if ($stop) {
                return ['label' => $stop['text'], 'lat' => $stop['lat'], 'lng' => $stop['lng']];
            }
        }
        if ($phase === 'return') {
            return ['label' => 'Return to pickup', 'lat' => $plan->pickup_lat !== null ? (float) $plan->pickup_lat : null, 'lng' => $plan->pickup_lng !== null ? (float) $plan->pickup_lng : null];
        }

        return ['label' => (string) ($plan->drop_text ?: 'Drop'), 'lat' => $plan->drop_lat !== null ? (float) $plan->drop_lat : null, 'lng' => $plan->drop_lng !== null ? (float) $plan->drop_lng : null];
    }

    private function driverFocusTrip(object $plan): ?object
    {
        $today = Carbon::now('Asia/Kolkata')->toDateString();
        $todayTrip = DB::table('corporate_daily_trips')->where('corporate_booking_id', $plan->id)->where('trip_date', $today)->first();
        if ($todayTrip && (string) $todayTrip->status !== 'skipped') {
            return $todayTrip;
        }
        $next = DB::table('corporate_daily_trips')
            ->where('corporate_booking_id', $plan->id)
            ->where('trip_date', '>=', $today)
            ->where('status', 'scheduled')
            ->orderBy('trip_date')
            ->first();

        return $next ?: $todayTrip;
    }

    private function guardPhase(User $actor, string $tripId, string $phase): object
    {
        $this->boot();
        $trip = $this->driverTripRow($actor, $tripId);
        abort_unless((string) $trip->phase === $phase, 422, 'This corporate day is not at that step.');

        return $trip;
    }

    private function driverTripRow(User $actor, string $tripId): object
    {
        $trip = $this->trip($this->numericId($tripId));
        $plan = $this->row((int) $trip->corporate_booking_id);
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        abort_unless($driverId > 0 && $driverId === (int) $plan->driver_id, 403, 'This corporate day is assigned to another driver.');

        return $trip;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePlan(array $data): array
    {
        $plans = $this->catalogPlans();
        $id = (string) ($data['planId'] ?? '');
        $key = strtoupper((string) ($data['planKey'] ?? ''));
        foreach ($plans as $plan) {
            if ($id !== '' && (string) $plan['id'] === $id) {
                return $plan;
            }
            if ($key !== '' && strtoupper((string) $plan['key']) === $key) {
                return $plan;
            }
        }
        foreach ($plans as $plan) {
            if ((int) ($plan['durationDays'] ?? 0) >= 7) {
                return $plan;
            }
        }

        return $plans[0] ?? ['id' => null, 'key' => 'DAY_30', 'title' => '30 Day Corporate', 'pricingMode' => 'VEHICLE', 'pricePaise' => 0, 'gstPercent' => 5, 'durationDays' => 30];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function catalogPlans(): array
    {
        if (! Schema::hasTable('corporate_plans')) {
            return [];
        }
        $q = DB::table('corporate_plans')->orderBy('sort_order')->orderBy('id');
        if (Schema::hasColumn('corporate_plans', 'status')) {
            $q->whereIn('status', ['PUBLISHED', 'published', 'ACTIVE', 'active']);
        }

        return $q->get()->map(function ($row) {
            $days = (int) ($row->duration_days ?? 0);
            if ($days < 1) {
                $days = match (strtoupper((string) ($row->plan_key ?? ''))) {
                    'DAY_7' => 7,
                    'DAY_15' => 15,
                    'DAY_30', 'MONTHLY', 'EMPLOYEE' => 30,
                    default => 0,
                };
            }

            return [
                'id' => (string) $row->id,
                'key' => $row->plan_key ?? ('PLAN'.$row->id),
                'title' => $row->title,
                'subtitle' => $row->subtitle,
                'pricingMode' => $row->pricing_mode ?? 'VEHICLE',
                'pricePaise' => (int) ($row->price_paise ?? 0),
                'gstPercent' => (float) ($row->gst_percent ?? 5),
                'durationDays' => $days,
            ];
        })->all();
    }

    private function planTitle(object $row): string
    {
        if ($row->plan_id && Schema::hasTable('corporate_plans')) {
            $title = DB::table('corporate_plans')->where('id', $row->plan_id)->value('title');
            if ($title) {
                return (string) $title;
            }
        }

        return (string) ($row->plan_key ?: 'Corporate plan');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sharePaise(array $data, string $prefix, int $commission, mixed $current): int
    {
        $paiseKey = $prefix.'Paise';
        $snake = strtolower(preg_replace('/([A-Z])/', '_$1', $prefix) ?? $prefix).'_paise';
        if (array_key_exists($paiseKey, $data) || array_key_exists($snake, $data)) {
            return max(0, (int) ($data[$paiseKey] ?? $data[$snake] ?? 0));
        }
        $percentKey = $prefix.'Percent';
        if (isset($data[$percentKey]) && is_numeric($data[$percentKey])) {
            return (int) round($commission * ((float) $data[$percentKey]) / 100);
        }

        return max(0, (int) ($current ?? 0));
    }

    /**
     * @return array{stateId: ?int, districtId: ?int}
     */
    private function territory(User $actor, array $data): array
    {
        $lat = isset($data['pickupLat']) ? (float) $data['pickupLat'] : null;
        $lng = isset($data['pickupLng']) ? (float) $data['pickupLng'] : null;
        $stateId = Schema::hasColumn('users', 'state_id') && $actor->state_id ? (int) $actor->state_id : null;
        $districtId = DistrictLocator::resolve($lat, $lng, (string) ($data['pickupText'] ?? ''), $stateId);
        if (! $districtId && Schema::hasColumn('users', 'district_id') && $actor->district_id) {
            $districtId = (int) $actor->district_id;
        }
        if (! $stateId && $districtId && Schema::hasTable('districts')) {
            $stateId = (int) (DB::table('districts')->where('id', $districtId)->value('state_id') ?? 0) ?: null;
        }

        return ['stateId' => $stateId, 'districtId' => $districtId];
    }

    private function districtHeadUser(mixed $districtId): ?int
    {
        if (! $districtId || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'district_id')) {
            return null;
        }
        $id = DB::table('users')->where('role', 'DISTRICT_HEAD')->where('district_id', $districtId)->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    private function commissionPercent(): float
    {
        if (! Schema::hasTable('commission_rules')) {
            return 10.0;
        }
        $percent = DB::table('commission_rules')->when(Schema::hasColumn('commission_rules', 'active'), fn ($q) => $q->where('active', 1))->orderBy('id')->value('percent');

        return is_numeric($percent) ? (float) $percent : 10.0;
    }

    private function platformUserId(): int
    {
        return (int) (DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->value('id') ?? 0);
    }

    private function markExpired(object $row): void
    {
        if (! $row->ends_on || in_array((string) $row->status, ['EXPIRED', 'COMPLETED', 'REJECTED', 'CANCELLED', 'REQUESTED'], true)) {
            return;
        }
        $ends = Carbon::parse((string) $row->ends_on)->endOfDay();
        if ($ends->isFuture()) {
            return;
        }
        $live = DB::table('corporate_daily_trips')->where('corporate_booking_id', $row->id)->where('status', 'live')->exists();
        if ($live) {
            return;
        }
        DB::table('corporate_travel_bookings')->where('id', $row->id)->update($this->only('corporate_travel_bookings', [
            'status' => 'EXPIRED',
            'updated_at' => now(),
        ]));
    }

    private function visible(User $actor, string $id): object
    {
        $row = $this->row($this->numericId($id));
        if (in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $row;
        }
        if (in_array($actor->role, ['CUSTOMER', 'CORPORATE'], true)) {
            abort_unless((int) $row->customer_id === (int) $actor->id, 403);
            return $row;
        }
        if ($actor->role === 'DRIVER') {
            $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
            abort_unless($driverId > 0 && $driverId === (int) $row->driver_id, 403);
            return $row;
        }
        if ($actor->role === 'STATE_HEAD') {
            abort_unless($actor->state_id && (int) $row->state_id === (int) $actor->state_id, 403);
            return $row;
        }
        if (in_array($actor->role, ['DISTRICT_HEAD', 'FRANCHISE'], true)) {
            abort_unless($actor->district_id && (int) $row->district_id === (int) $actor->district_id, 403);
            return $row;
        }
        abort(403);
    }

    private function owned(User $actor, string $id): object
    {
        $row = $this->row($this->numericId($id));
        abort_unless((int) $row->customer_id === (int) $actor->id, 403);

        return $row;
    }

    private function row(int $id): object
    {
        $row = DB::table('corporate_travel_bookings')->where('id', $id)->first();
        abort_unless($row, 404, 'Corporate plan not found');

        return $row;
    }

    private function trip(int $id): object
    {
        $row = DB::table('corporate_daily_trips')->where('id', $id)->first();
        abort_unless($row, 404, 'Corporate day not found');

        return $row;
    }

    /**
     * @return list<array{text: string, lat: float, lng: float}>
     */
    private function stops(object $row): array
    {
        return $this->normalizeStops($this->decode($row->stops_json ?? null));
    }

    /**
     * @return list<array{text: string, lat: float, lng: float}>
     */
    private function normalizeStops(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        $stops = [];
        if (! is_array($raw)) {
            return [];
        }
        foreach ($raw as $stop) {
            if (! is_array($stop) || ! isset($stop['lat'], $stop['lng'])) {
                continue;
            }
            $stops[] = [
                'text' => (string) ($stop['text'] ?? $stop['address'] ?? 'Stop'),
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ];
        }

        return $stops;
    }

    private function kind(array $data): string
    {
        $raw = strtoupper((string) ($data['tripKind'] ?? $data['serviceType'] ?? 'ONE_WAY'));

        return match ($raw) {
            'ROUND', 'ROUND_TRIP', 'ROUND_WAY' => 'ROUND_WAY',
            'MULTI', 'MULTI_STOP', 'MULTISTOP' => 'MULTI_STOP',
            default => 'ONE_WAY',
        };
    }

    private function kindOf(object $row): string
    {
        return $this->kind(['tripKind' => $row->trip_kind]);
    }

    private function kindLabel(string $kind): string
    {
        return match ($this->kind(['tripKind' => $kind])) {
            'ROUND_WAY' => 'Round Trip',
            'MULTI_STOP' => 'Multi-stop',
            default => 'One Way',
        };
    }

    private function startDate(array $data): Carbon
    {
        $raw = (string) ($data['travelDate'] ?? $data['startsOn'] ?? $data['eventDate'] ?? '');
        abort_if($raw === '', 422, 'Plan start date is required.');
        $date = Carbon::parse($raw, 'Asia/Kolkata')->startOfDay();
        abort_if($date->lt(Carbon::now('Asia/Kolkata')->startOfDay()), 422, 'Plan start date cannot be in the past.');

        return $date;
    }

    private function clock(mixed $value): string
    {
        $parts = preg_split('/[:.]/', (string) $value) ?: [];
        $hour = max(0, min(23, (int) ($parts[0] ?? 9)));
        $minute = max(0, min(59, (int) ($parts[1] ?? 0)));

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function isToday(mixed $date): bool
    {
        return Carbon::parse((string) $date)->toDateString() === Carbon::now('Asia/Kolkata')->toDateString();
    }

    private function timesOverlap(string $left, string $right): bool
    {
        return abs($this->minutes($left) - $this->minutes($right)) < 120;
    }

    private function minutes(string $time): int
    {
        $parts = preg_split('/[:.]/', $time) ?: [];

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    private function label(string $status, bool $expired, int $daysLeft): string
    {
        if ($expired && ! in_array($status, ['REJECTED', 'CANCELLED', 'COMPLETED'], true)) {
            return 'Plan expired';
        }

        return match ($status) {
            'REQUESTED' => 'Waiting for approval',
            'APPROVED' => 'Pay '.\App\Support\AdvancePayment::percent('CORPORATE').'%',
            'APPROVED_10' => 'Pay remaining 90%',
            'PAID_100' => 'Paid in full',
            'ASSIGNED' => 'Driver assigned',
            'LIVE' => 'Today\'s ride is live',
            'COMPLETED' => 'Plan completed',
            'EXPIRED' => 'Plan expired',
            'REJECTED' => 'Rejected',
            'CANCELLED' => 'Cancelled',
            default => $status,
        };
    }

    private function lifecycle(string $status): string
    {
        return match ($status) {
            'COMPLETED', 'EXPIRED' => 'completed',
            'REJECTED', 'CANCELLED' => 'cancelled',
            'LIVE' => 'corporate',
            'ASSIGNED', 'PAID_100', 'APPROVED', 'APPROVED_10' => 'pending_review',
            default => 'pending_review',
        };
    }

    private function phaseLabel(string $phase, string $status): string
    {
        return match ($phase) {
            'activated' => 'At pickup',
            'outbound' => 'On the way',
            'waiting' => 'Waiting',
            'return' => 'Returning',
            'completed' => 'Day complete',
            'skipped' => 'Skipped',
            default => $status === 'scheduled' ? 'Scheduled' : $phase,
        };
    }

    /**
     * @return list<string>
     */
    private function actions(string $status, bool $otp, bool $invoice, bool $driver): array
    {
        $actions = ['details'];
        if ($status === 'APPROVED') {
            $actions[] = 'pay10';
        }
        if ($status === 'APPROVED_10') {
            $actions[] = 'pay90';
        }
        if ($driver && in_array($status, ['ASSIGNED', 'LIVE'], true)) {
            $actions[] = 'track';
        }
        if ($otp) {
            $actions[] = 'otp';
        }
        if ($invoice) {
            $actions[] = 'invoice';
        }
        if (in_array($status, ['EXPIRED', 'COMPLETED', 'ASSIGNED'], true)) {
            $actions[] = 'requestRenew';
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function driverActions(string $phase, string $kind): array
    {
        $kind = $this->kind(['tripKind' => $kind]);

        return match ($phase) {
            'idle' => ['activate', 'call', 'navigate'],
            'activated' => ['depart', 'call', 'navigate'],
            'outbound' => $kind === 'ROUND_WAY' ? ['outbound', 'call', 'navigate'] : ($kind === 'MULTI_STOP' ? ['stop', 'call', 'navigate'] : ['complete', 'call', 'navigate']),
            'waiting' => ['return', 'call'],
            'return' => ['complete', 'call', 'navigate'],
            default => ['call'],
        };
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    private function driverGps(int $userId, object $plan): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $lat = $user->last_lat ?? $plan->pickup_lat;
        $lng = $user->last_lng ?? $plan->pickup_lng;

        return ['lat' => $lat !== null ? (float) $lat : null, 'lng' => $lng !== null ? (float) $lng : null];
    }

    private function decode(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function numericId(string $id): int
    {
        return (int) preg_replace('/\D/', '', $id);
    }

    private function ledgerExists(string $ref): bool
    {
        if (! Schema::hasTable('wallet_ledger')) {
            return false;
        }
        $q = DB::table('wallet_ledger')->where('public_ref', $ref);
        if (Schema::hasColumn('wallet_ledger', 'payment_ref')) {
            $q = DB::table('wallet_ledger')->where(function ($inner) use ($ref) {
                $inner->where('public_ref', $ref)->orWhere('payment_ref', $ref);
            });
        }

        return $q->exists();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function push(array $userIds, string $title, string $body, string $event): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return;
        }
        try {
            app(FcmPushService::class)->notifyUsers($ids, $title, $body, ['type' => 'corporate', 'event' => $event]);
        } catch (\Throwable) {
        }
    }

    private function notifyOpsAndHeads(object $row, string $title, string $body, string $event): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        $ids = DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($row->state_id && Schema::hasColumn('users', 'state_id')) {
            $ids = array_merge($ids, DB::table('users')->where('role', 'STATE_HEAD')->where('state_id', $row->state_id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }
        if ($row->district_id && Schema::hasColumn('users', 'district_id')) {
            $ids = array_merge($ids, DB::table('users')->where('role', 'DISTRICT_HEAD')->where('district_id', $row->district_id)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }
        $this->push($ids, $title, $body, $event);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function only(string $table, array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if (Schema::hasColumn($table, $key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function boot(): void
    {
        CorporatePlanSchema::ensure();
    }
}
