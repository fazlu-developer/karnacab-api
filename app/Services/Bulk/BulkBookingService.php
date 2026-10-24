<?php

namespace App\Services\Bulk;

use App\Models\User;
use App\Services\AppSurfaceService;
use App\Services\FcmPushService;
use App\Services\WalletAccountService;
use App\Support\BulkSchema;
use App\Support\Geo;
use App\Support\VehicleCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Bulk booking is its own job: one parent and one child cab per car.
 * REQUESTED → CONFIRMED_UNPAID → ADVANCE_PAID → ASSIGNED → LIVE → COMPLETED
 * The 10% advance is platform commission, taken once after admin confirm and before drivers are assigned.
 */
class BulkBookingService
{
    public function create(User $actor, array $data): array
    {
        $this->boot();
        $kind = $this->kind($data);
        $when = $this->when($data);
        $passengers = (int) ($data['passengers'] ?? $data['passengerTotal'] ?? 0);
        abort_if($passengers < 1, 422, 'Enter at least 1 passenger.');
        $pickupText = trim((string) ($data['pickupText'] ?? ''));
        abort_if($pickupText === '' || ! isset($data['pickupLat'], $data['pickupLng']), 422, 'Pickup location is required.');
        if ($kind !== 'MULTI_STOP') {
            abort_if(trim((string) ($data['dropText'] ?? '')) === '' || ! isset($data['dropLat'], $data['dropLng']), 422, 'Drop location is required.');
        }
        $quote = app(AppSurfaceService::class)->bulkQuote($data);
        $lines = $quote['lines'] ?? [];
        $cabs = $this->cabDrafts($lines);
        abort_if(count($cabs) < 2, 422, 'Bulk booking needs at least 2 cabs.');
        $stops = is_array($quote['stops'] ?? null) ? $quote['stops'] : [];
        if ($kind === 'MULTI_STOP') {
            abort_if($stops === [], 422, 'Add the stops every cab will follow.');
        }
        $total = (int) ($quote['totalPaise'] ?? 0);
        abort_if($total < 1, 422, 'No fare is published for this bulk trip.');
        $advance = (int) round($total * 0.10);
        $this->splitCommission($cabs, $advance, $total);
        $ref = 'BLK'.random_int(1000000, 9999999);
        $id = DB::transaction(function () use ($actor, $data, $quote, $cabs, $stops, $kind, $when, $passengers, $total, $advance, $ref, $pickupText) {
            $id = DB::table('bulk_bookings')->insertGetId($this->only('bulk_bookings', [
                'public_ref' => $ref,
                'customer_id' => $actor->id,
                'status' => 'REQUESTED',
                'event_key' => $data['eventKey'] ?? 'CORPORATE',
                'category' => $cabs[0]['category'] ?? 'SEDAN',
                'trip_kind' => $kind,
                'quote_paise' => $total,
                'advance_paise' => $advance,
                'advance_paid_paise' => 0,
                'commission_paise' => $advance,
                'waiting_extra_paise' => 0,
                'waiting_paise_per_min' => (int) ($quote['waitingPaisePerMin'] ?? 0),
                'distance_km' => $quote['distanceKm'] ?? null,
                'pickup_text' => $pickupText,
                'drop_text' => $data['dropText'] ?? ($stops[count($stops) - 1]['text'] ?? null),
                'pickup_lat' => $data['pickupLat'],
                'pickup_lng' => $data['pickupLng'],
                'drop_lat' => $data['dropLat'] ?? ($stops[count($stops) - 1]['lat'] ?? null),
                'drop_lng' => $data['dropLng'] ?? ($stops[count($stops) - 1]['lng'] ?? null),
                'event_date' => $when->toDateString(),
                'event_time' => $when->format('H:i'),
                'vehicle_count' => count($cabs),
                'passengers' => $passengers,
                'passengers_per_cab' => $data['passengersPerCab'] ?? null,
                'requirements' => $data['requirements'] ?? null,
                'lines_json' => json_encode($quote['lines'] ?? []),
                'quote_snapshot' => json_encode($quote),
                'stops_json' => json_encode($stops),
                'payment_method' => null,
                'payment_status' => 'unpaid',
                'contact_name' => $data['contactName'] ?? $actor->name,
                'contact_phone' => $data['contactPhone'] ?? $actor->phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            foreach ($cabs as $i => $cab) {
                DB::table('bulk_booking_cabs')->insert($this->only('bulk_booking_cabs', [
                    'bulk_booking_id' => $id,
                    'cab_no' => $i + 1,
                    'category' => $cab['category'],
                    'status' => 'pending',
                    'phase' => 'idle',
                    'fare_paise' => $cab['fare'],
                    'commission_paise' => $cab['commission'],
                    'stop_index' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }

            return $id;
        });
        $row = $this->row($id);
        $this->notifyOps('Bulk booking submitted', $row->public_ref.' needs confirmation. '.$row->vehicle_count.' cabs.', 'bulk_requested');

        $presented = $this->present($row, $actor);
        $presented['message'] = 'Bulk booking scheduled successfully';
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
        $row = $this->visible($actor, $id);
        $presented = $this->present($row, $actor);
        $presented['tracking'] = in_array((string) $row->status, ['ASSIGNED', 'LIVE'], true);

        return $presented;
    }

    public function confirm(int $id): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'Only a requested bulk booking can be confirmed.');
        DB::table('bulk_bookings')->where('id', $id)->update($this->only('bulk_bookings', [
            'status' => 'CONFIRMED_UNPAID',
            'invoice_no' => $row->invoice_no ?: ('BINV'.strtoupper(Str::random(8))),
            'confirmed_at' => now(),
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        $this->push([(int) $fresh->customer_id], 'Bulk booking confirmed', 'Pay the 10% advance on '.$fresh->public_ref.' so drivers can be assigned.', 'bulk_confirmed');

        return $this->present($fresh);
    }

    public function reject(int $id, string $reason): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'This bulk booking can no longer be rejected.');
        $reason = trim($reason);
        abort_if($reason === '', 422, 'A reject reason is required.');
        DB::table('bulk_bookings')->where('id', $id)->update($this->only('bulk_bookings', [
            'status' => 'REJECTED',
            'reject_reason' => $reason,
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        $this->push([(int) $fresh->customer_id], 'Bulk booking not confirmed', $reason, 'bulk_rejected');

        return $this->present($fresh);
    }

    public function payAdvance(User $actor, string $id, array $data): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        $method = strtoupper((string) ($data['method'] ?? $data['paymentMethod'] ?? 'ONLINE'));
        abort_if(str_contains($method, 'CASH'), 422, 'Cash is not accepted for the bulk advance. Pay online or with wallet.');
        if ((string) $row->status !== 'CONFIRMED_UNPAID' && (int) $row->advance_paid_paise >= (int) $row->advance_paise && (int) $row->advance_paise > 0) {
            return $this->present($row, $actor);
        }
        abort_unless($row->status === 'CONFIRMED_UNPAID', 422, 'The 10% advance is due after admin confirms the booking.');
        if (str_contains($method, 'WALLET')) {
            $fresh = $this->settleAdvance($row, 'WALLET', 'BULK'.$row->id.'-ADVANCE', $actor);
            $this->notifyOps('Bulk advance paid', 'Assign '.$fresh->vehicle_count.' drivers for '.$fresh->public_ref.'.', 'bulk_paid');

            return $this->present($fresh, $actor);
        }
        $payu = app(\App\Services\PayUService::class)->startBulkAdvance($actor, ((int) $row->advance_paise) / 100, (int) $row->id);

        return [
            'booking' => $this->present($row, $actor),
            'payment' => $payu,
            'needsAdvance' => true,
        ];
    }

    public function settlePayu(int $userId, string $bulkId, string $txnid, int $amountPaise): void
    {
        $this->boot();
        if ($bulkId === '' || $txnid === '') {
            return;
        }
        $row = DB::table('bulk_bookings')->where('id', $this->numericId($bulkId))->first();
        if (! $row || (int) $row->customer_id !== $userId) {
            return;
        }
        if ((string) $row->status !== 'CONFIRMED_UNPAID') {
            return;
        }
        abort_unless($amountPaise === (int) $row->advance_paise, 422, 'Bulk advance amount does not match 10% of the grand total.');
        $actor = User::query()->find($userId);
        abort_unless($actor, 404);
        $fresh = $this->settleAdvance($row, 'ONLINE', $txnid, $actor);
        $this->notifyOps('Bulk advance paid', 'Assign '.$fresh->vehicle_count.' drivers for '.$fresh->public_ref.'.', 'bulk_paid');
    }

    public function cancel(User $actor, string $id): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        abort_unless(in_array($row->status, ['REQUESTED', 'CONFIRMED_UNPAID'], true), 422, 'After the 10% advance, cancellation is an admin decision.');
        DB::table('bulk_bookings')->where('id', $row->id)->update($this->only('bulk_bookings', [
            'status' => 'CANCELLED',
            'updated_at' => now(),
        ]));

        return $this->present($this->row((int) $row->id), $actor);
    }

    /**
     * @param  list<int>  $driverIds
     */
    public function assign(int $id, array $driverIds): array
    {
        $this->boot();
        $row = $this->row($id);
        $cabs = $this->cabs((int) $row->id);
        $n = count($cabs);
        $ids = array_map('intval', array_values($driverIds));
        abort_unless(count($ids) === $n, 422, 'Assign remaining drivers. This booking needs exactly '.$n.' drivers.');
        abort_unless(count($ids) === count(array_unique($ids)), 422, 'Each cab needs a different driver.');
        if ($row->status === 'ASSIGNED' || $row->status === 'LIVE') {
            $current = array_map(fn ($cab) => (int) $cab->driver_id, $cabs);
            if ($current === $ids) {
                return $this->present($row);
            }
            abort(422, 'Drivers are already assigned to this bulk booking.');
        }
        abort_unless($row->status === 'ADVANCE_PAID', 422, 'Pay the 10% advance before assigning drivers.');
        $picked = [];
        foreach ($ids as $i => $driverId) {
            $picked[] = $this->driverForCab($cabs[$i], $driverId, $row);
        }
        $otp = $row->start_otp ?: (string) random_int(1000, 9999);
        DB::transaction(function () use ($row, $cabs, $picked, $otp) {
            foreach ($cabs as $i => $cab) {
                $driver = $picked[$i];
                DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
                    'driver_id' => $driver['driverId'],
                    'driver_user_id' => $driver['userId'],
                    'vehicle_id' => $driver['vehicleId'],
                    'driver_name' => $driver['name'],
                    'driver_phone' => $driver['phone'],
                    'plate' => $driver['plate'],
                    'status' => 'assigned',
                    'phase' => 'idle',
                    'updated_at' => now(),
                ]));
            }
            DB::table('bulk_bookings')->where('id', $row->id)->update($this->only('bulk_bookings', [
                'status' => 'ASSIGNED',
                'start_otp' => $otp,
                'assigned_at' => now(),
                'updated_at' => now(),
            ]));
        });
        $fresh = $this->row((int) $row->id);
        $this->push([(int) $fresh->customer_id], 'Bulk drivers assigned', count($picked).' cabs are assigned on '.$fresh->public_ref.'. Share the start OTP.', 'bulk_assigned');
        $this->push(array_column($picked, 'userId'), 'Bulk job assigned', 'You have one cab on '.$fresh->public_ref.'. Call the customer, then activate with their OTP.', 'bulk_assigned');

        return $this->present($fresh);
    }

    public function activate(User $actor, string $cabId, string $otp): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        abort_unless(in_array($parent->status, ['ASSIGNED', 'LIVE'], true), 422, 'This bulk job is not ready to start.');
        if ((string) $cab->phase !== 'idle') {
            return $this->driverTrip($cab, $parent);
        }
        abort_unless(hash_equals((string) $parent->start_otp, trim($otp)), 422, 'That start OTP does not match.');
        $gps = $this->driverGps((int) $cab->driver_user_id, $parent);
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'status' => 'live',
            'phase' => 'activated',
            'lat' => $gps['lat'],
            'lng' => $gps['lng'],
            'activated_at' => now(),
            'updated_at' => now(),
        ]));
        if ($parent->status === 'ASSIGNED') {
            DB::table('bulk_bookings')->where('id', $parent->id)->update($this->only('bulk_bookings', [
                'status' => 'LIVE',
                'updated_at' => now(),
            ]));
        }
        $this->push([(int) $parent->customer_id], 'Cab '.$cab->cab_no.' started', 'Cab '.$cab->cab_no.' of '.$parent->vehicle_count.' is on the map.', 'bulk_activated');

        return $this->driverTrip($this->cab((int) $cab->id), $this->row((int) $parent->id));
    }

    public function location(User $actor, string $cabId, float $lat, float $lng): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        abort_if((string) $cab->phase === 'idle' || (string) $cab->status === 'pending', 422, 'Activate this cab before sending location.');
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'lat' => $lat,
            'lng' => $lng,
            'updated_at' => now(),
        ]));

        return ['ok' => true, 'cabId' => (string) $cab->id];
    }

    public function depart(User $actor, string $cabId): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        abort_unless((string) $cab->phase === 'activated', 422, 'Activate this cab, then leave pickup.');
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'phase' => 'outbound',
            'status' => 'live',
            'updated_at' => now(),
        ]));

        return $this->driverTrip($this->cab((int) $cab->id), $parent);
    }

    public function completeOutbound(User $actor, string $cabId): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        abort_unless($this->kindOf($parent) === 'ROUND_WAY', 422, 'Outbound waiting is only for a round trip.');
        abort_unless((string) $cab->phase === 'outbound', 422, 'Leave pickup before ending the outbound leg.');
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'phase' => 'waiting',
            'wait_started_at' => now(),
            'outbound_completed_at' => now(),
            'updated_at' => now(),
        ]));

        return $this->driverTrip($this->cab((int) $cab->id), $parent);
    }

    public function startReturn(User $actor, string $cabId): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        abort_unless($this->kindOf($parent) === 'ROUND_WAY', 422, 'Return is only for a round trip.');
        abort_unless((string) $cab->phase === 'waiting', 422, 'Finish the outbound leg before starting the return.');
        $minutes = $this->waitMinutes($cab);
        $charge = $minutes * (int) ($parent->waiting_paise_per_min ?? 0);
        DB::transaction(function () use ($cab, $parent, $minutes, $charge) {
            DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
                'phase' => 'return',
                'wait_minutes' => $minutes,
                'wait_charge_paise' => $charge,
                'return_started_at' => now(),
                'updated_at' => now(),
            ]));
            DB::table('bulk_bookings')->where('id', $parent->id)->update($this->only('bulk_bookings', [
                'waiting_extra_paise' => (int) ($parent->waiting_extra_paise ?? 0) + $charge,
                'updated_at' => now(),
            ]));
        });

        return $this->driverTrip($this->cab((int) $cab->id), $this->row((int) $parent->id));
    }

    public function completeStop(User $actor, string $cabId, array $data = []): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        abort_unless($this->kindOf($parent) === 'MULTI_STOP', 422, 'Stops belong to a multi-stop bulk booking.');
        abort_unless((string) $cab->phase === 'outbound', 422, 'Leave pickup before completing a stop.');
        $stops = $this->stops($parent);
        $done = (int) $cab->stop_index;
        abort_if($done >= count($stops), 422, 'Every stop on this cab is already complete.');
        $done++;
        if ($done >= count($stops)) {
            return $this->finishCab($cab, $parent, ! empty($data['collected']));
        }
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'stop_index' => $done,
            'phase' => 'outbound',
            'updated_at' => now(),
        ]));

        return $this->driverTrip($this->cab((int) $cab->id), $parent);
    }

    public function completeCab(User $actor, string $cabId, array $data = []): array
    {
        $this->boot();
        $cab = $this->driverCab($actor, $cabId);
        $parent = $this->row((int) $cab->bulk_booking_id);
        $kind = $this->kindOf($parent);
        if ($kind === 'ROUND_WAY') {
            abort_unless((string) $cab->phase === 'return', 422, 'Start the return before completing this cab. The bulk job stays open after the outbound leg.');
        } elseif ($kind === 'MULTI_STOP') {
            abort(422, 'Complete each stop. This cab finishes on the last stop.');
        } else {
            abort_unless((string) $cab->phase === 'outbound', 422, 'Leave pickup before completing this cab.');
        }

        return $this->finishCab($cab, $parent, ! empty($data['collected']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customerCards(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('bulk_bookings')) {
            return [];
        }

        return DB::table('bulk_bookings')->where('customer_id', $actor->id)->orderByDesc('id')->limit(40)->get()
            ->map(fn ($row) => $this->present($row, $actor))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function driverCards(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('bulk_booking_cabs') || ! Schema::hasTable('drivers')) {
            return [];
        }
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        if ($driverId < 1) {
            return [];
        }
        $cabs = DB::table('bulk_booking_cabs')->where('driver_id', $driverId)->orderByDesc('id')->limit(40)->get();
        $parents = DB::table('bulk_bookings')->whereIn('id', $cabs->pluck('bulk_booking_id')->all() ?: [0])->get()->keyBy('id');
        $cards = [];
        foreach ($cabs as $cab) {
            $parent = $parents->get($cab->bulk_booking_id);
            if (! $parent || ! in_array((string) $parent->status, ['ASSIGNED', 'LIVE', 'COMPLETED'], true)) {
                continue;
            }
            if ((int) ($parent->advance_paid_paise ?? 0) < 1) {
                continue;
            }
            $cards[] = $this->driverTrip($cab, $parent);
        }

        return $cards;
    }

    private function finishCab(object $cab, object $parent, bool $collected): array
    {
        $due = $this->cabDue($cab);
        DB::table('bulk_booking_cabs')->where('id', $cab->id)->update($this->only('bulk_booking_cabs', [
            'status' => 'completed',
            'phase' => 'completed',
            'completed_at' => now(),
            'collected_paise' => $collected ? $due : (int) ($cab->collected_paise ?? 0),
            'updated_at' => now(),
        ]));
        if ($collected) {
            $this->noteCollected((int) $cab->driver_user_id, (int) $parent->id, (int) $cab->id, $due, (int) $cab->fare_paise);
        }
        $left = DB::table('bulk_booking_cabs')->where('bulk_booking_id', $parent->id)->where('phase', '!=', 'completed')->count();
        if ($left === 0) {
            DB::table('bulk_bookings')->where('id', $parent->id)->update($this->only('bulk_bookings', [
                'status' => 'COMPLETED',
                'updated_at' => now(),
            ]));
            $this->push([(int) $parent->customer_id], 'Bulk booking completed', 'All cabs on '.$parent->public_ref.' are done.', 'bulk_completed');
        } else {
            $done = (int) $parent->vehicle_count - $left;
            $this->push([(int) $parent->customer_id], 'Cab '.$cab->cab_no.' completed', $done.'/'.$parent->vehicle_count.' cabs done on '.$parent->public_ref.'.', 'bulk_cab_completed');
        }

        return $this->driverTrip($this->cab((int) $cab->id), $this->row((int) $parent->id));
    }

    private function settleAdvance(object $row, string $method, string $paymentRef, User $actor): object
    {
        return DB::transaction(function () use ($row, $method, $paymentRef, $actor) {
            $locked = DB::table('bulk_bookings')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($locked, 404);
            $advance = (int) $locked->advance_paise;
            if ((int) $locked->advance_paid_paise >= $advance && $advance > 0 && (string) $locked->status !== 'CONFIRMED_UNPAID') {
                return $locked;
            }
            abort_unless($locked->status === 'CONFIRMED_UNPAID', 422, 'This bulk booking is not waiting for the 10% advance.');
            if ($method === 'WALLET' && ! $this->ledgerExists($paymentRef)) {
                $this->debitCustomer((int) $actor->id, $advance, $paymentRef);
            }
            $this->creditPlatform((int) $locked->id, $advance, (int) $locked->quote_paise);
            DB::table('bulk_bookings')->where('id', $locked->id)->update($this->only('bulk_bookings', [
                'status' => 'ADVANCE_PAID',
                'payment_status' => 'advance_paid',
                'payment_method' => $method,
                'advance_paid_paise' => $advance,
                'advance_payment_ref' => $paymentRef,
                'commission_paise' => $advance,
                'updated_at' => now(),
            ]));

            return $this->row((int) $locked->id);
        });
    }

    /**
     * @param  list<array{category: string, fare: int, commission?: int}>  $cabs
     */
    private function splitCommission(array &$cabs, int $advance, int $grand): void
    {
        $running = 0;
        $last = count($cabs) - 1;
        foreach ($cabs as $i => &$cab) {
            if ($i === $last) {
                $cab['commission'] = max(0, $advance - $running);
            } else {
                $cab['commission'] = (int) floor(((int) $cab['fare']) * $advance / max(1, $grand));
                $running += $cab['commission'];
            }
        }
        unset($cab);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{category: string, fare: int}>
     */
    private function cabDrafts(array $lines): array
    {
        $cabs = [];
        foreach ($lines as $line) {
            $count = (int) ($line['count'] ?? 0);
            $lineTotal = (int) ($line['lineTotalPaise'] ?? 0);
            if ($count < 1 || $lineTotal < 1) {
                continue;
            }
            $each = intdiv($lineTotal, $count);
            $rem = $lineTotal - ($each * $count);
            for ($i = 0; $i < $count; $i++) {
                $cabs[] = [
                    'category' => strtoupper((string) ($line['category'] ?? 'SEDAN')),
                    'fare' => $each + ($i === $count - 1 ? $rem : 0),
                ];
            }
        }

        return $cabs;
    }

    /**
     * @return array{driverId: int, userId: int, vehicleId: ?int, name: string, phone: string, plate: string}
     */
    private function driverForCab(object $cab, int $driverId, object $parent): array
    {
        $driver = DB::table('drivers')->where('id', $driverId)->first();
        abort_unless($driver, 422, 'Choose a driver from the list.');
        $user = DB::table('users')->where('id', $driver->user_id)->first();
        abort_unless($user, 422, 'That driver has no login.');
        $vehicles = Schema::hasTable('vehicles')
            ? DB::table('vehicles')->where('driver_id', $driverId)->get()
            : collect();
        $match = $vehicles->first(fn ($vehicle) => VehicleCategory::sameFamily((string) ($vehicle->category ?? ''), (string) $cab->category));
        abort_unless($match, 422, trim(($user->name ?? 'Driver').' is not in the '.$cab->category.' category.'));
        $this->assertFree($driverId, $parent, (int) $cab->id);

        return [
            'driverId' => $driverId,
            'userId' => (int) $user->id,
            'vehicleId' => isset($match->id) ? (int) $match->id : null,
            'name' => (string) ($user->name ?? 'Driver'),
            'phone' => (string) ($user->phone ?? ''),
            'plate' => (string) ($match->registration_no ?? ''),
        ];
    }

    private function assertFree(int $driverId, object $parent, int $cabId): void
    {
        $slot = $this->slot($parent);
        $hit = DB::table('bulk_booking_cabs as cabs')
            ->join('bulk_bookings as jobs', 'jobs.id', '=', 'cabs.bulk_booking_id')
            ->where('cabs.driver_id', $driverId)
            ->where('cabs.id', '!=', $cabId)
            ->whereNotIn('jobs.status', ['REJECTED', 'CANCELLED', 'COMPLETED'])
            ->where('jobs.event_date', $slot['date'])
            ->get(['jobs.event_time', 'jobs.public_ref', 'cabs.phase']);
        foreach ($hit as $row) {
            if ((string) $row->phase === 'completed') {
                continue;
            }
            if ($this->timesOverlap((string) $row->event_time, $slot['time'])) {
                abort(422, 'This driver is already booked at that date and time.');
            }
        }
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'driver_id')) {
            return;
        }
        $busy = DB::table('bookings')
            ->where('driver_id', $driverId)
            ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'CUSTOMER_CANCELLED', 'DRIVER_CANCELLED', 'EXPIRED'])
            ->when(Schema::hasColumn('bookings', 'scheduled_at'), fn ($q) => $q->whereNotNull('scheduled_at'))
            ->get(['scheduled_at', 'product']);
        foreach ($busy as $booking) {
            if (! $booking->scheduled_at) {
                continue;
            }
            $at = \Carbon\Carbon::parse($booking->scheduled_at)->timezone('Asia/Kolkata');
            if ($at->toDateString() === $slot['date'] && $this->timesOverlap($at->format('H:i'), $slot['time'])) {
                abort(422, 'This driver is already booked at that date and time.');
            }
        }
    }

    private function timesOverlap(string $left, string $right): bool
    {
        $a = $this->minutes($left);
        $b = $this->minutes($right);

        return abs($a - $b) < 120;
    }

    private function minutes(string $time): int
    {
        $parts = preg_split('/[:.]/', $time) ?: [];

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }

    /**
     * @return array{date: string, time: string}
     */
    private function slot(object $parent): array
    {
        return [
            'date' => (string) $parent->event_date,
            'time' => substr((string) $parent->event_time, 0, 5),
        ];
    }

    private function waitMinutes(object $cab): int
    {
        if (! $cab->wait_started_at) {
            return 0;
        }
        $seconds = abs(\Carbon\Carbon::parse($cab->wait_started_at)->diffInSeconds(now()));

        return (int) ceil($seconds / 60);
    }

    private function cabDue(object $cab): int
    {
        $fare = (int) ($cab->fare_paise ?? 0);
        $commission = (int) ($cab->commission_paise ?? 0);

        return max(0, $fare - $commission) + (int) ($cab->wait_charge_paise ?? 0);
    }

    /**
     * @return array{lat: ?float, lng: ?float}
     */
    private function driverGps(int $userId, object $parent): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $lat = $user->last_lat ?? null;
        $lng = $user->last_lng ?? null;
        if ($lat === null || $lng === null) {
            $lat = $parent->pickup_lat;
            $lng = $parent->pickup_lng;
        }

        return ['lat' => $lat !== null ? (float) $lat : null, 'lng' => $lng !== null ? (float) $lng : null];
    }

    private function present(object $row, ?User $viewer = null): array
    {
        $quote = $this->decode($row->quote_snapshot ?? null);
        $stops = $this->stops($row);
        $cabs = $this->cabs((int) $row->id);
        $status = (string) ($row->status ?? 'REQUESTED');
        $advance = (int) ($row->advance_paise ?? 0);
        $paid = (int) ($row->advance_paid_paise ?? 0);
        $wait = (int) ($row->waiting_extra_paise ?? 0);
        $total = (int) ($row->quote_paise ?? 0);
        $grand = $total + $wait;
        $showOtp = in_array($status, ['ASSIGNED', 'LIVE'], true) && ($viewer === null || $viewer->role !== 'DRIVER');
        $assigned = 0;
        $done = 0;
        $cars = [];
        foreach ($cabs as $cab) {
            if ((int) ($cab->driver_id ?? 0) > 0) {
                $assigned++;
            }
            if ((string) $cab->phase === 'completed') {
                $done++;
            }
            $cars[] = $this->car($cab, $row, $stops);
        }
        $n = max(1, (int) ($row->vehicle_count ?? count($cabs)));
        $needsAdvance = $status === 'CONFIRMED_UNPAID';
        $customer = $viewer && (int) $viewer->id === (int) $row->customer_id ? $viewer : null;

        return [
            'id' => 'bulk-'.$row->id,
            'bulkId' => (string) $row->id,
            'ok' => true,
            'kind' => 'bulk',
            'product' => 'BULK',
            'publicRef' => $row->public_ref,
            'status' => $status,
            'statusLabel' => $this->label($status, $assigned, $n),
            'lifecycle' => $this->lifecycle($status),
            'lifecycleLabel' => $this->label($status, $assigned, $n),
            'tripBucket' => in_array($status, ['COMPLETED', 'CANCELLED', 'REJECTED'], true) ? 'history' : 'upcoming',
            'tripKind' => $row->trip_kind ?? 'ONE_WAY',
            'tripKindLabel' => $this->kindLabel((string) ($row->trip_kind ?? 'ONE_WAY')),
            'eventKey' => $row->event_key ?? null,
            'category' => $row->category,
            'vehicleCount' => (int) ($row->vehicle_count ?? count($cabs)),
            'assignedCount' => $assigned,
            'doneCount' => $done,
            'progressLabel' => $done.'/'.$n.' done',
            'assignRemaining' => $status === 'ADVANCE_PAID' && $assigned < $n,
            'passengers' => $row->passengers ?? null,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
            'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
            'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
            'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
            'eventDate' => $row->event_date ?? null,
            'eventTime' => $row->event_time ?? null,
            'scheduledAt' => $this->scheduledIso($row),
            'createdAt' => $row->created_at ?? null,
            'requirements' => $row->requirements ?? null,
            'paymentMethod' => $row->payment_method,
            'paymentStatus' => $row->payment_status,
            'contactName' => $row->contact_name ?? ($customer->name ?? null),
            'contactPhone' => $row->contact_phone ?? ($customer->phone ?? null),
            'lines' => $quote['lines'] ?? $this->decode($row->lines_json ?? null),
            'stops' => $stops,
            'stopCount' => count($stops),
            'nextStop' => $this->groupNextStop($cabs, count($stops)),
            'distanceKm' => $row->distance_km !== null ? (float) $row->distance_km : ($quote['distanceKm'] ?? null),
            'totalKm' => $row->distance_km !== null ? (float) $row->distance_km : ($quote['distanceKm'] ?? null),
            'needsAdvance' => $needsAdvance,
            'needsTourAdvance' => false,
            'advanceAfterAccept' => false,
            'tracking' => in_array($status, ['ASSIGNED', 'LIVE'], true),
            'startOtp' => $showOtp ? ($row->start_otp ?? null) : null,
            'invoiceNo' => $row->invoice_no ?? null,
            'rejectReason' => $row->reject_reason ?? null,
            'canCancel' => in_array($status, ['REQUESTED', 'CONFIRMED_UNPAID'], true),
            'cars' => $cars,
            'quote' => $quote,
            'fare' => [
                'basePaise' => (int) ($quote['basePaise'] ?? 0),
                'driverAllowPaise' => (int) ($quote['driverAllowPaise'] ?? 0),
                'gstPaise' => (int) ($quote['gstPaise'] ?? 0),
                'waitingExtraPaise' => $wait,
                'waitingExtraRupees' => $wait / 100,
                'totalPaise' => $grand,
                'totalRupees' => $grand / 100,
                'advancePaise' => $advance,
                'advanceRupees' => $advance / 100,
                'advancePercent' => 10,
                'balancePaise' => max(0, $total - $advance) + $wait,
                'balanceRupees' => (max(0, $total - $advance) + $wait) / 100,
                'currency' => 'INR',
            ],
            'quotePaise' => $grand,
            'quoteRupees' => $grand / 100,
            'invoice' => $row->invoice_no ? $this->invoiceMap($row, $quote, $grand, $wait) : null,
            'allowedActions' => $this->actions($status, $showOtp, (bool) $row->invoice_no),
        ];
    }

    /**
     * @param  list<object>  $cabs
     */
    private function groupNextStop($cabs, int $stopCount): ?int
    {
        if ($stopCount < 1) {
            return null;
        }
        $next = $stopCount;
        $started = false;
        foreach ($cabs as $cab) {
            if ((string) $cab->phase === 'completed' || (string) $cab->phase === 'idle') {
                continue;
            }
            $started = true;
            $next = min($next, (int) $cab->stop_index + 1);
        }

        return $started ? $next : 1;
    }

    private function car(object $cab, object $parent, array $stops): array
    {
        $phase = (string) ($cab->phase ?? 'idle');
        $target = $this->target($cab, $parent, $stops);
        $show = $phase !== 'idle' && $phase !== 'pending' && $cab->lat !== null && $cab->lng !== null;
        $eta = null;
        if ($show && $target['lat'] !== null) {
            $km = Geo::haversineKm((float) $cab->lat, (float) $cab->lng, (float) $target['lat'], (float) $target['lng']);
            $eta = max(1, (int) round(($km / 25) * 60));
        }

        return [
            'id' => (string) $cab->id,
            'cabId' => (string) $cab->id,
            'cabNo' => (int) $cab->cab_no,
            'category' => $cab->category,
            'driverName' => $cab->driver_name,
            'driverPhone' => $cab->driver_phone,
            'plate' => $cab->plate,
            'status' => $cab->status,
            'phase' => $phase,
            'statusLabel' => $phase === 'idle' ? 'Waiting to start' : ucfirst(str_replace('_', ' ', $phase)),
            'showOnMap' => $show,
            'lat' => $show ? (float) $cab->lat : null,
            'lng' => $show ? (float) $cab->lng : null,
            'stopIndex' => (int) ($cab->stop_index ?? 0),
            'waitMinutes' => (int) ($cab->wait_minutes ?? 0),
            'waitChargeRupees' => ((int) ($cab->wait_charge_paise ?? 0)) / 100,
            'etaMinutes' => $eta,
            'targetLabel' => $target['label'],
            'targetLat' => $target['lat'],
            'targetLng' => $target['lng'],
        ];
    }

    /**
     * @param  list<array{text: string, lat: float, lng: float}>  $stops
     * @return array{label: string, lat: ?float, lng: ?float}
     */
    private function target(object $cab, object $parent, array $stops): array
    {
        $phase = (string) ($cab->phase ?? 'idle');
        $kind = $this->kindOf($parent);
        $pickup = [
            'label' => (string) ($parent->pickup_text ?? 'Pickup'),
            'lat' => $parent->pickup_lat !== null ? (float) $parent->pickup_lat : null,
            'lng' => $parent->pickup_lng !== null ? (float) $parent->pickup_lng : null,
        ];
        $drop = [
            'label' => (string) ($parent->drop_text ?? 'Drop'),
            'lat' => $parent->drop_lat !== null ? (float) $parent->drop_lat : null,
            'lng' => $parent->drop_lng !== null ? (float) $parent->drop_lng : null,
        ];
        if ($phase === 'return' || ($phase === 'completed' && $kind === 'ROUND_WAY')) {
            return $pickup;
        }
        if ($phase === 'idle' || $phase === 'activated') {
            return $pickup;
        }
        if ($kind === 'MULTI_STOP' && $stops !== []) {
            $index = min((int) ($cab->stop_index ?? 0), count($stops) - 1);
            $stop = $stops[$index];

            return [
                'label' => 'Stop '.($index + 1).' · '.$stop['text'],
                'lat' => (float) $stop['lat'],
                'lng' => (float) $stop['lng'],
            ];
        }

        return $drop;
    }

    private function driverTrip(object $cab, object $parent): array
    {
        $presented = $this->present($parent);
        $phase = (string) ($cab->phase ?? 'idle');
        $kind = $this->kindOf($parent);
        $target = $this->target($cab, $parent, $this->stops($parent));
        $due = $this->cabDue($cab);
        unset($presented['startOtp']);

        return $presented + [
            'id' => 'bulk-cab-'.$cab->id,
            'cabId' => (string) $cab->id,
            'kind' => 'bulk',
            'product' => 'BULK',
            'cabNo' => (int) $cab->cab_no,
            'cabTotal' => (int) $parent->vehicle_count,
            'title' => 'Bulk job, cab '.$cab->cab_no.' of '.$parent->vehicle_count,
            'category' => $cab->category,
            'phase' => $phase,
            'status' => $phase === 'completed' ? 'COMPLETED' : ($phase === 'idle' ? 'SCHEDULED' : 'ONGOING'),
            'lifecycle' => $phase === 'completed' ? 'completed' : ($phase === 'idle' ? 'scheduled' : 'started'),
            'statusLabel' => $phase === 'idle' ? 'Waiting to start' : ucfirst($phase),
            'customerPhone' => $parent->contact_phone,
            'plate' => $cab->plate,
            'canActivate' => $phase === 'idle',
            'canCancel' => false,
            'collectRupees' => $due / 100,
            'targetLabel' => $target['label'],
            'targetLat' => $target['lat'],
            'targetLng' => $target['lng'],
            'stopIndex' => (int) ($cab->stop_index ?? 0),
            'actions' => array_values(array_filter([
                'call',
                $phase === 'idle' ? 'activate' : null,
                $phase === 'activated' ? 'depart' : null,
                $phase === 'outbound' && $kind === 'ONE_WAY' ? 'complete' : null,
                $phase === 'outbound' && $kind === 'ROUND_WAY' ? 'outbound' : null,
                $phase === 'waiting' ? 'return' : null,
                $phase === 'return' ? 'complete' : null,
                $phase === 'outbound' && $kind === 'MULTI_STOP' ? 'stop' : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    private function invoiceMap(object $row, array $quote, int $grand, int $wait): array
    {
        $gst = (int) ($quote['gstPaise'] ?? 0);

        return [
            'publicRef' => $row->invoice_no ?: $row->public_ref,
            'customerName' => $row->contact_name,
            'customerPhone' => $row->contact_phone,
            'pickup' => $row->pickup_text,
            'drop' => $row->drop_text,
            'category' => 'Bulk '.$row->trip_kind.' · '.$row->vehicle_count.' cabs',
            'product' => 'BULK',
            'distanceKm' => $row->distance_km,
            'gstPaise' => $gst,
            'gstRupees' => $gst / 100,
            'subtotalRupees' => max(0, $grand - $gst) / 100,
            'totalPaise' => $grand,
            'totalRupees' => $grand / 100,
            'waitingExtraRupees' => $wait / 100,
            'advanceRupees' => ((int) $row->advance_paid_paise) / 100,
            'balanceRupees' => (max(0, (int) $row->quote_paise - (int) $row->advance_paise) + $wait) / 100,
            'paymentMode' => $row->payment_method ?: 'ADVANCE',
            'issuedAt' => (string) ($row->confirmed_at ?? $row->created_at ?? ''),
        ];
    }

    /**
     * @return list<string>
     */
    private function actions(string $status, bool $otp, bool $invoice): array
    {
        return array_values(array_filter([
            $status === 'CONFIRMED_UNPAID' ? 'pay' : null,
            in_array($status, ['ASSIGNED', 'LIVE'], true) ? 'track' : null,
            $otp ? 'otp' : null,
            $invoice ? 'invoice' : null,
            in_array($status, ['REQUESTED', 'CONFIRMED_UNPAID'], true) ? 'cancel' : null,
        ]));
    }

    private function label(string $status, int $assigned, int $n): string
    {
        return match ($status) {
            'REQUESTED' => 'Requested / Waiting for admin',
            'CONFIRMED_UNPAID' => 'Confirmed · pay 10%',
            'ADVANCE_PAID' => $assigned < $n ? 'Assign remaining drivers' : 'Advance paid',
            'ASSIGNED' => 'Drivers assigned',
            'LIVE' => 'Live',
            'COMPLETED' => 'Completed',
            'REJECTED' => 'Rejected',
            'CANCELLED' => 'Cancelled',
            default => $status,
        };
    }

    private function lifecycle(string $status): string
    {
        return match ($status) {
            'REQUESTED' => 'pending_review',
            'CONFIRMED_UNPAID', 'ADVANCE_PAID', 'ASSIGNED' => 'scheduled',
            'LIVE' => 'bulk_live',
            'COMPLETED' => 'completed',
            'REJECTED', 'CANCELLED' => 'cancelled',
            default => 'upcoming',
        };
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'ROUND_WAY' => 'Round Trip',
            'MULTI_STOP' => 'Multi-stop',
            default => 'One Way',
        };
    }

    private function kind(array $data): string
    {
        $raw = strtoupper((string) ($data['tripKind'] ?? $data['product'] ?? 'ONE_WAY'));

        return match ($raw) {
            'ROUND', 'ROUND_TRIP', 'ROUND_WAY' => 'ROUND_WAY',
            'MULTI', 'MULTI_STOP', 'MULTISTOP' => 'MULTI_STOP',
            'ONE_WAY', 'ONEWAY' => 'ONE_WAY',
            default => abort(422, 'Bulk booking type must be One Way, Round Trip, or Multi-stop.'),
        };
    }

    private function kindOf(object $row): string
    {
        return strtoupper((string) ($row->trip_kind ?? 'ONE_WAY'));
    }

    private function when(array $data): \Carbon\Carbon
    {
        $date = trim((string) ($data['eventDate'] ?? ''));
        $time = trim((string) ($data['eventTime'] ?? ''));
        abort_if($date === '' || $time === '', 422, 'Choose a date and time. Bulk booking is always scheduled.');
        try {
            $when = \Carbon\Carbon::parse($date.' '.$time, 'Asia/Kolkata');
        } catch (\Throwable) {
            abort(422, 'Choose a valid date and time.');
        }
        abort_unless($when->greaterThan(now()), 422, 'Choose a future date and time. Bulk booking is always scheduled.');

        return $when;
    }

    private function scheduledIso(object $row): ?string
    {
        if (! $row->event_date) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($row->event_date.' '.($row->event_time ?: '00:00'), 'Asia/Kolkata')->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<object>
     */
    private function cabs(int $bulkId): array
    {
        if (! Schema::hasTable('bulk_booking_cabs')) {
            return [];
        }

        return DB::table('bulk_booking_cabs')->where('bulk_booking_id', $bulkId)->orderBy('cab_no')->orderBy('id')->get()->all();
    }

    private function cab(int $id): object
    {
        $cab = DB::table('bulk_booking_cabs')->where('id', $id)->first();
        abort_unless($cab, 404, 'Cab not found');

        return $cab;
    }

    private function driverCab(User $actor, string $cabId): object
    {
        $cab = $this->cab($this->numericId($cabId));
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        abort_unless($actor->role === 'DRIVER' && ((int) $cab->driver_user_id === (int) $actor->id || ($driverId > 0 && (int) $cab->driver_id === $driverId)), 403, 'This cab is assigned to another driver.');

        return $cab;
    }

    private function visible(User $actor, string $id): object
    {
        $row = $this->row($this->numericId($id));
        if (in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true) || (int) $row->customer_id === (int) $actor->id) {
            return $row;
        }
        if ($actor->role === 'DRIVER') {
            $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
            $owns = DB::table('bulk_booking_cabs')->where('bulk_booking_id', $row->id)->where('driver_id', $driverId)->exists();
            abort_unless($owns, 403);
            abort_unless(in_array((string) $row->status, ['ASSIGNED', 'LIVE', 'COMPLETED'], true), 403, 'This bulk job is not assigned yet.');

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
        $row = DB::table('bulk_bookings')->where('id', $id)->first();
        abort_unless($row, 404, 'Bulk booking not found');

        return $row;
    }

    /**
     * @return list<array{text: string, lat: float, lng: float}>
     */
    private function stops(object $row): array
    {
        $decoded = $this->decode($row->stops_json ?? null);
        $stops = [];
        if (is_array($decoded)) {
            foreach ($decoded as $stop) {
                if (! is_array($stop) || ! isset($stop['lat'], $stop['lng'])) {
                    continue;
                }
                $stops[] = [
                    'text' => (string) ($stop['text'] ?? 'Stop'),
                    'lat' => (float) $stop['lat'],
                    'lng' => (float) $stop['lng'],
                ];
            }
        }

        return $stops;
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

    private function debitCustomer(int $userId, int $amount, string $ref): void
    {
        if ($amount < 1 || $this->ledgerExists($ref)) {
            return;
        }
        $wallet = app(WalletAccountService::class)->ensureWallet($userId, 'CUSTOMER');
        abort_unless($wallet, 422, 'Wallet not found');
        $before = (int) $wallet->balance_paise;
        abort_unless($before >= $amount, 422, 'Insufficient wallet balance');
        $after = $before - $amount;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        $this->insertLedger($wallet, $userId, 'CUSTOMER', 'DEBIT', $amount, $before, $after, 'bulk_advance', 'Bulk 10% advance', $ref);
    }

    private function creditPlatform(int $bulkId, int $amount, int $gross): void
    {
        $userId = (int) (DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->value('id') ?? 0);
        if ($userId < 1 || $amount < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'PLATFORM',
            0,
            $amount,
            $gross,
            'bulk_commission',
            'Bulk commission 10% of grand total',
            ['PLATFORM', 'ADMIN'],
            'BULK'.$bulkId.'-PLATFORM',
        );
    }

    private function noteCollected(int $driverUserId, int $bulkId, int $cabId, int $amount, int $gross): void
    {
        $ref = 'BULK'.$bulkId.'-CAB'.$cabId.'-COLLECT';
        if ($driverUserId < 1 || $amount < 1 || $this->ledgerExists($ref)) {
            return;
        }
        $wallet = app(WalletAccountService::class)->ensureWallet($driverUserId, 'DRIVER');
        if (! $wallet) {
            return;
        }
        $balance = (int) $wallet->balance_paise;
        $this->insertLedger($wallet, $driverUserId, 'DRIVER', 'CREDIT', $amount, $balance, $balance, 'collected_outside', 'Bulk cab balance collected by the driver. Platform 10% was already paid.', $ref, $gross);
    }

    private function insertLedger(object $wallet, int $userId, string $account, string $direction, int $amount, int $before, int $after, string $kind, string $note, string $ref, int $gross = 0): void
    {
        if (! Schema::hasTable('wallet_ledger')) {
            return;
        }
        DB::table('wallet_ledger')->insert($this->only('wallet_ledger', [
            'public_ref' => $ref,
            'payment_ref' => $ref,
            'wallet_id' => $wallet->id,
            'owner_user_id' => $userId,
            'account' => $account,
            'owner_type' => $account,
            'direction' => $direction,
            'amount_paise' => $amount,
            'gross_paise' => $gross ?: $amount,
            'commission_paise' => 0,
            'balance_before_paise' => $before,
            'balance_after_paise' => $after,
            'kind' => $kind,
            'status' => 'posted',
            'note' => $note,
            'created_at' => now(),
        ]));
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
        $ids = array_values(array_filter($userIds, fn ($id) => (int) $id > 0));
        if ($ids === []) {
            return;
        }
        try {
            app(FcmPushService::class)->notifyUsers($ids, $title, $body, ['type' => 'bulk', 'event' => $event]);
        } catch (\Throwable) {
        }
    }

    private function notifyOps(string $title, string $body, string $event): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }
        $ids = DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all();
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
        BulkSchema::ensure();
    }
}
