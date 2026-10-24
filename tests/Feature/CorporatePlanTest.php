<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Corporate\CorporatePlanService;
use App\Services\WalletAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CorporatePlanTest extends TestCase
{
    private CorporatePlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->plans = app(CorporatePlanService::class);
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thirty_day_plan_pays_10_then_90_and_credits_the_driver(): void
    {
        $admin = $this->user('ADMIN');
        $customer = $this->user('CUSTOMER', 3, 9);
        $this->wallet($customer, 'CUSTOMER', 5000000);
        $head = $this->user('DISTRICT_HEAD', 3, 9);
        $fleetUser = $this->user('FLEET_OWNER', 3, 9);
        $fleetId = DB::table('fleet_owners')->insertGetId([
            'user_id' => $fleetUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driver = $this->driver('Ravi', '9000000011', $fleetId);
        $other = $this->driver('Other', '9000000012', null);
        $when = now()->timezone('Asia/Kolkata');

        $created = $this->plans->create($customer, $this->payload($when, 'ONE_WAY', 'DAY_30'));
        $this->assertSame('Corporate plan requested', $created['message']);
        $this->assertSame('REQUESTED', $created['status']);
        $this->assertSame('Waiting for approval', $created['statusLabel']);
        $this->assertSame('CORPORATE', $created['product']);
        $this->assertFalse($created['needsPlanPay10']);
        $this->assertFalse($created['renewAvailable']);
        $this->assertCount(30, $created['history']);
        $this->assertSame(0, DB::table('corporate_daily_trips')->whereNotNull('driver_id')->count());
        if (Schema::hasTable('bookings')) {
            $this->assertSame(0, DB::table('bookings')->where('product', 'CORPORATE')->count());
        }

        $id = (int) $created['planId'];
        $approved = $this->plans->approve($id);
        $this->assertSame('APPROVED', $approved['status']);
        $this->assertTrue($approved['needsPlanPay10']);
        $total = (int) $approved['fare']['totalPaise'];
        $this->assertSame((int) round($total * 0.10), (int) $approved['fare']['pay10Paise']);
        $this->assertSame($total - (int) $approved['fare']['pay10Paise'], (int) $approved['fare']['pay90Paise']);

        try {
            $this->plans->assign($id, $driver['driverId']);
            $this->fail('Assign before full payment should fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        try {
            $this->plans->pay10($customer, (string) $id, ['method' => 'CASH']);
            $this->fail('Cash should be rejected');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $paid10 = $this->plans->pay10($customer, (string) $id, ['method' => 'WALLET']);
        $this->assertSame('APPROVED_10', $paid10['status']);
        $this->assertTrue($paid10['needsPlanPay90']);
        $again = $this->plans->pay10($customer, (string) $id, ['method' => 'WALLET']);
        $this->assertSame('APPROVED_10', $again['status']);
        $this->assertSame(1, DB::table('wallet_ledger')->where('public_ref', 'CORP'.$id.'-PAY10-CUSTOMER')->count());

        $paid = $this->plans->pay90($customer, (string) $id, ['method' => 'WALLET']);
        $this->assertSame('PAID_100', $paid['status']);
        $this->assertFalse($paid['needsPlanPay90']);
        $this->assertNotEmpty($paid['invoiceNo']);
        $this->assertSame($total, (int) DB::table('wallets')->where('owner_user_id', $admin->id)->where('owner_type', 'PLATFORM')->value('balance_paise'));

        $assigned = $this->plans->assign($id, $driver['driverId']);
        $this->assertSame('ASSIGNED', $assigned['status']);
        $this->assertNotEmpty($assigned['startOtp']);
        $this->assertSame('Ravi', $assigned['driver']['name']);
        $driverShare = (int) $assigned['fare']['driverSharePaise'];
        $this->assertSame($driverShare, (int) DB::table('wallets')->where('owner_user_id', $driver['user']->id)->value('balance_paise'));
        $this->assertSame(1, DB::table('wallet_ledger')->where('kind', 'corporate_plan')->where('public_ref', 'CORP'.$id.'-DRIVER')->count());
        $this->assertSame($id, (int) DB::table('wallet_ledger')->where('public_ref', 'CORP'.$id.'-DRIVER')->value('booking_id'));
        $this->plans->assign($id, $driver['driverId']);
        $this->assertSame(1, DB::table('wallet_ledger')->where('public_ref', 'CORP'.$id.'-DRIVER')->count());

        $withdraw = app(WalletAccountService::class)->requestWithdraw($driver['user'], 500, 'ravi@upi', 'UPI');
        $this->assertSame('requested', $withdraw['status']);

        $tripId = (string) $assigned['dailyTrip']['id'];
        $live = $this->plans->activate($driver['user'], $tripId, (string) $assigned['startOtp']);
        $this->assertSame('activated', $live['phase']);
        $this->plans->depart($driver['user'], $tripId);
        $done = $this->plans->completeDay($driver['user'], $tripId);
        $this->assertSame('completed', $done['phase']);
        $this->assertSame(0, (int) $done['collectRupees']);

        $moved = $this->plans->updateTimetable($admin, (string) $id, ['pickupTime' => '10:30']);
        $this->assertSame('10:30', $moved['pickupTime']);
        $today = $when->toDateString();
        $this->assertSame('09:00', DB::table('corporate_daily_trips')->where('corporate_booking_id', $id)->where('trip_date', $today)->value('pickup_time'));
        $this->assertSame('10:30', DB::table('corporate_daily_trips')->where('corporate_booking_id', $id)->where('status', 'scheduled')->value('pickup_time'));

        try {
            $this->plans->updateTimetable($customer, (string) $id, ['pickupTime' => '11:00']);
            $this->fail('Customer must not edit the timetable');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $tomorrow = $when->copy()->addDay()->toDateString();
        $skipId = (string) DB::table('corporate_daily_trips')->where('corporate_booking_id', $id)->where('trip_date', $tomorrow)->value('id');
        $before = DB::table('wallet_ledger')->where('kind', 'corporate_plan')->count();
        $skipped = $this->plans->skip($driver['user'], $skipId, 'Customer no-show');
        $this->assertSame('skipped', $skipped['phase']);
        $this->assertSame('skipped', DB::table('corporate_daily_trips')->where('id', $skipId)->value('status'));
        $this->assertSame($before, DB::table('wallet_ledger')->where('kind', 'corporate_plan')->count());

        $shared = $this->plans->setShares($id, ['districtHeadPaise' => 40000, 'fleetOwnerPaise' => 20000]);
        $this->assertSame(40000, $shared['fare']['districtHeadPaise']);
        $this->assertSame(20000, $shared['fare']['fleetOwnerPaise']);
        $this->assertSame(40000, (int) DB::table('wallets')->where('owner_user_id', $head->id)->value('balance_paise'));
        $this->assertSame(20000, (int) DB::table('wallets')->where('owner_user_id', $fleetUser->id)->value('balance_paise'));
        $this->plans->setShares($id, ['districtHeadPaise' => 40000, 'fleetOwnerPaise' => 20000]);
        $this->assertSame(1, DB::table('wallet_ledger')->where('public_ref', 'CORP'.$id.'-DISTRICT')->count());
        $this->assertSame(1, DB::table('wallet_ledger')->where('public_ref', 'CORP'.$id.'-FLEET')->count());

        $cards = $this->plans->driverCards($driver['user']);
        $this->assertNotEmpty($cards);
        $this->assertSame('CORPORATE', $cards[0]['product']);
        $this->assertTrue($cards[0]['prepaid']);

        $second = $this->plans->create($customer, $this->payload($when, 'ONE_WAY', 'DAY_7'));
        $secondId = (int) $second['planId'];
        $this->plans->approve($secondId);
        $this->plans->pay10($customer, (string) $secondId, ['method' => 'WALLET']);
        $this->plans->pay90($customer, (string) $secondId, ['method' => 'WALLET']);
        try {
            $this->plans->assign($secondId, $driver['driverId']);
            $this->fail('Overlapping corporate time should fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $otherAssigned = $this->plans->assign($secondId, $other['driverId']);
        $this->assertSame('ASSIGNED', $otherAssigned['status']);

        $renewed = $this->plans->renew($id, ['durationDays' => 7]);
        $this->assertSame((string) $id, $renewed['renewedFromId']);
        $this->assertFalse($renewed['renewAvailable']);
        $this->assertSame('REQUESTED', $renewed['status']);
        $asked = $this->plans->requestRenew($customer, (string) $id);
        $this->assertFalse($asked['renewAvailable']);
        $this->assertTrue($asked['renewRequested']);
    }

    public function test_round_and_multi_stop_finish_only_that_day(): void
    {
        $this->user('ADMIN');
        $customer = $this->user('CUSTOMER', 3, 9);
        $this->wallet($customer, 'CUSTOMER', 5000000);
        $driver = $this->driver('Ravi', '9000000021', null);
        $when = now()->timezone('Asia/Kolkata');

        $round = $this->ready($customer, $driver['driverId'], $this->payload($when, 'ROUND_WAY', 'DAY_7'));
        $trip = (string) $round['dailyTrip']['id'];
        $this->plans->activate($driver['user'], $trip, (string) $round['startOtp']);
        $this->plans->depart($driver['user'], $trip);
        $waiting = $this->plans->completeOutbound($driver['user'], $trip);
        $this->assertSame('waiting', $waiting['phase']);
        try {
            $this->plans->completeDay($driver['user'], $trip);
            $this->fail('Round day must return before it completes');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->plans->startReturn($driver['user'], $trip);
        $done = $this->plans->completeDay($driver['user'], $trip);
        $this->assertSame('completed', $done['phase']);
        $this->assertSame('ASSIGNED', DB::table('corporate_travel_bookings')->where('id', $round['planId'])->value('status'));

        $multi = $this->ready($customer, $driver['driverId'], $this->payload($when->copy()->addDays(40), 'MULTI_STOP', 'DAY_7', [
            ['text' => 'Office', 'lat' => 25.60, 'lng' => 85.14],
            ['text' => 'Plant', 'lat' => 25.62, 'lng' => 85.16],
        ]));
        $this->assertNull($multi['dailyTrip']);
        $future = DB::table('corporate_daily_trips')->where('corporate_booking_id', $multi['planId'])->orderBy('trip_date')->value('id');
        $this->travelToDay((int) $future);
        $shown = $this->plans->show($customer, (string) $multi['planId']);
        $day = (string) $shown['dailyTrip']['id'];
        $this->plans->activate($driver['user'], $day, (string) $shown['startOtp']);
        $this->plans->depart($driver['user'], $day);
        $mid = $this->plans->completeStop($driver['user'], $day);
        $this->assertSame(1, $mid['stopIndex']);
        $last = $this->plans->completeStop($driver['user'], $day);
        $this->assertSame('completed', $last['phase']);
    }

    /**
     * @param  list<array{text: string, lat: float, lng: float}>  $stops
     * @return array<string, mixed>
     */
    private function payload(\Carbon\Carbon $when, string $kind, string $plan, array $stops = []): array
    {
        return [
            'planKey' => $plan,
            'tripKind' => $kind,
            'category' => 'SEDAN',
            'passengers' => 3,
            'pickupText' => 'Patna Junction',
            'dropText' => 'Gaya Station',
            'pickupLat' => 25.5941,
            'pickupLng' => 85.1376,
            'dropLat' => 24.7955,
            'dropLng' => 84.9994,
            'travelDate' => $when->toDateString(),
            'pickupTime' => '09:00',
            'stops' => $stops,
            'contactName' => 'Asha',
            'contactPhone' => '9800000000',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ready(User $customer, int $driverId, array $payload): array
    {
        $created = $this->plans->create($customer, $payload);
        $id = (int) $created['planId'];
        $this->plans->approve($id);
        $this->plans->pay10($customer, (string) $id, ['method' => 'WALLET']);
        $this->plans->pay90($customer, (string) $id, ['method' => 'WALLET']);

        return $this->plans->assign($id, $driverId);
    }

    private function travelToDay(int $tripId): void
    {
        $date = (string) DB::table('corporate_daily_trips')->where('id', $tripId)->value('trip_date');
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($date, 'Asia/Kolkata')->setTime(8, 0));
    }

    private function user(string $role, ?int $stateId = null, ?int $districtId = null): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role.' user',
            'phone' => '98'.random_int(10000000, 99999999),
            'role' => $role,
            'state_id' => $stateId,
            'district_id' => $districtId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * @return array{user: User, driverId: int}
     */
    private function driver(string $name, string $phone, ?int $fleetId): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'role' => 'DRIVER',
            'state_id' => 3,
            'district_id' => 9,
            'last_lat' => 25.60,
            'last_lng' => 85.14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverId = DB::table('drivers')->insertGetId([
            'user_id' => $userId,
            'fleet_owner_id' => $fleetId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vehicles')->insert([
            'driver_id' => $driverId,
            'fleet_owner_id' => $fleetId,
            'category' => 'SEDAN',
            'registration_no' => 'BR01'.substr($phone, -4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => User::query()->findOrFail($userId), 'driverId' => $driverId];
    }

    private function wallet(User $user, string $type, int $paise): void
    {
        DB::table('wallets')->insert([
            'owner_user_id' => $user->id,
            'owner_type' => $type,
            'balance_paise' => $paise,
            'frozen_paise' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function schema(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->unsignedBigInteger('state_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamps();
        });
        Schema::create('drivers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('fleet_owner_id')->nullable();
            $table->timestamps();
        });
        Schema::create('vehicles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('fleet_owner_id')->nullable();
            $table->string('category')->nullable();
            $table->string('registration_no')->nullable();
            $table->timestamps();
        });
        Schema::create('fleet_owners', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('wallets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->bigInteger('balance_paise')->default(0);
            $table->bigInteger('frozen_paise')->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_ledger', function ($table) {
            $table->id();
            $table->string('public_ref')->nullable();
            $table->string('payment_ref')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('account')->nullable();
            $table->string('owner_type')->nullable();
            $table->string('direction')->nullable();
            $table->bigInteger('amount_paise')->default(0);
            $table->bigInteger('gross_paise')->default(0);
            $table->bigInteger('commission_paise')->default(0);
            $table->bigInteger('balance_before_paise')->default(0);
            $table->bigInteger('balance_after_paise')->default(0);
            $table->string('kind')->nullable();
            $table->string('status')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('bookings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('product')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('scheduled_at')->nullable();
        });
        Schema::create('bulk_rate_rules', function ($table) {
            $table->id();
            $table->string('category')->nullable();
            $table->string('trip_kind')->nullable();
            $table->unsignedBigInteger('per_vehicle_paise')->default(100000);
            $table->unsignedBigInteger('driver_allow_paise')->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        foreach (['ONE_WAY' => 100000, 'ROUND_WAY' => 180000, 'MULTI_STOP' => 150000] as $kind => $paise) {
            DB::table('bulk_rate_rules')->insert([
                'category' => 'SEDAN',
                'trip_kind' => $kind,
                'per_vehicle_paise' => $paise,
                'gst_percent' => 0,
                'active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
