<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Bulk\BulkBookingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BulkBookingTest extends TestCase
{
    private BulkBookingService $bulk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema();
        $this->bulk = app(BulkBookingService::class);
    }

    public function test_one_way_bulk_is_a_parent_with_one_cab_per_car(): void
    {
        $customer = $this->user('CUSTOMER');
        $this->wallet($customer, 500000);
        $drivers = [];
        for ($i = 1; $i <= 5; $i++) {
            $drivers[] = $this->driver('Sedan '.$i, '900000000'.$i);
        }
        $when = now()->addDay()->timezone('Asia/Kolkata');
        $created = $this->bulk->create($customer, $this->payload($when, 'ONE_WAY'));

        $this->assertSame('Bulk booking scheduled successfully', $created['message']);
        $this->assertSame('REQUESTED', $created['status']);
        $this->assertSame('BULK', $created['product']);
        $this->assertCount(5, $created['cars']);
        $this->assertFalse($created['needsAdvance']);
        $this->assertNull($created['startOtp']);
        $this->assertSame(0, DB::table('bulk_booking_cabs')->whereNotNull('driver_id')->count());
        if (Schema::hasTable('bookings')) {
            $this->assertSame(0, DB::table('bookings')->where('product', 'BULK')->count());
        }

        $id = (int) $created['bulkId'];
        $confirmed = $this->bulk->confirm($id);
        $this->assertSame('CONFIRMED_UNPAID', $confirmed['status']);
        $this->assertTrue($confirmed['needsAdvance']);
        $advance = (int) $confirmed['fare']['advancePaise'];
        $this->assertSame((int) round($confirmed['fare']['totalPaise'] * 0.10) - (int) $confirmed['fare']['waitingExtraPaise'], $advance);

        try {
            $this->bulk->assign($id, array_column($drivers, 'driverId'));
            $this->fail('Assign before payment should fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $paid = $this->bulk->payAdvance($customer, (string) $id, ['method' => 'WALLET']);
        $this->assertSame('ADVANCE_PAID', $paid['status']);
        $this->assertFalse($paid['needsAdvance']);
        $again = $this->bulk->payAdvance($customer, (string) $id, ['method' => 'WALLET']);
        $this->assertSame('ADVANCE_PAID', $again['status']);
        $this->assertSame(1, DB::table('wallet_ledger')->where('public_ref', 'BULK'.$id.'-ADVANCE')->count());

        try {
            $this->bulk->assign($id, [$drivers[0]['driverId'], $drivers[0]['driverId'], $drivers[1]['driverId'], $drivers[2]['driverId'], $drivers[3]['driverId']]);
            $this->fail('Duplicate driver should fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $assigned = $this->bulk->assign($id, array_column($drivers, 'driverId'));
        $this->assertSame('ASSIGNED', $assigned['status']);
        $this->assertNotEmpty($assigned['startOtp']);
        $this->assertCount(5, array_filter($assigned['cars'], fn ($car) => ($car['driverName'] ?? '') !== ''));
        $cards = $this->bulk->driverCards($drivers[1]['user']);
        $this->assertCount(1, $cards);
        $this->assertSame('Bulk job, cab 2 of 5', $cards[0]['title']);
        $this->assertFalse($cards[0]['canCancel']);
        $this->assertArrayNotHasKey('startOtp', $cards[0]);

        $busy = $this->driver('Sedan busy', '9111111111');
        $second = $this->bulk->create($customer, $this->payload($when, 'ONE_WAY', 2));
        $secondId = (int) $second['bulkId'];
        $this->bulk->confirm($secondId);
        $this->wallet($customer, 500000);
        $this->bulk->payAdvance($customer, (string) $secondId, ['method' => 'WALLET']);
        try {
            $this->bulk->assign($secondId, [$drivers[0]['driverId'], $busy['driverId']]);
            $this->fail('Overlapping driver should fail');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $otp = (string) $assigned['startOtp'];
        $cabId = (string) $assigned['cars'][0]['cabId'];
        $live = $this->bulk->activate($drivers[0]['user'], $cabId, $otp);
        $this->assertSame('activated', $live['phase']);
        $track = $this->bulk->track($customer, (string) $id);
        $visible = array_values(array_filter($track['cars'], fn ($car) => $car['showOnMap'] === true));
        $this->assertCount(5, $visible);
        $this->assertSame('SEDAN', $visible[0]['category']);
        $hidden = array_values(array_filter($track['cars'], fn ($car) => $car['showOnMap'] !== true));
        $this->assertCount(0, $hidden);

        $this->bulk->depart($drivers[0]['user'], $cabId);
        $done = $this->bulk->completeCab($drivers[0]['user'], $cabId, ['collected' => true]);
        $this->assertSame('completed', $done['phase']);
        $parent = $this->bulk->show($customer, (string) $id);
        $this->assertSame('LIVE', $parent['status']);
        $this->assertSame('1/5 done', $parent['progressLabel']);
    }

    public function test_round_trip_wait_is_added_per_cab_and_does_not_finish_on_outbound(): void
    {
        $customer = $this->user('CUSTOMER');
        $this->wallet($customer, 500000);
        $a = $this->driver('Round A', '9222222221');
        $b = $this->driver('Round B', '9222222222');
        $when = now()->addDays(2)->timezone('Asia/Kolkata');
        $created = $this->bulk->create($customer, $this->payload($when, 'ROUND_WAY', 2));
        $id = (int) $created['bulkId'];
        $this->bulk->confirm($id);
        $this->bulk->payAdvance($customer, (string) $id, ['method' => 'WALLET']);
        $assigned = $this->bulk->assign($id, [$a['driverId'], $b['driverId']]);
        $cabId = (string) $assigned['cars'][0]['cabId'];
        DB::table('bulk_bookings')->where('id', $id)->update(['waiting_paise_per_min' => 200]);
        $this->bulk->activate($a['user'], $cabId, (string) $assigned['startOtp']);
        $this->bulk->depart($a['user'], $cabId);
        $this->bulk->completeOutbound($a['user'], $cabId);
        DB::table('bulk_booking_cabs')->where('id', $cabId)->update(['wait_started_at' => now()->subMinutes(10)]);
        $back = $this->bulk->startReturn($a['user'], $cabId);
        $this->assertSame('return', $back['phase']);
        $this->assertSame('LIVE', $this->bulk->show($customer, (string) $id)['status']);
        $finished = $this->bulk->completeCab($a['user'], $cabId, ['collected' => true]);
        $this->assertGreaterThan(0, (int) DB::table('bulk_bookings')->where('id', $id)->value('waiting_extra_paise'));
        $this->assertSame('completed', $finished['phase']);
    }

    public function test_multi_stop_follows_the_same_stops_and_finishes_on_the_last_one(): void
    {
        $customer = $this->user('CUSTOMER');
        $this->wallet($customer, 500000);
        $a = $this->driver('Stop A', '9333333331');
        $b = $this->driver('Stop B', '9333333332');
        $when = now()->addDays(3)->timezone('Asia/Kolkata');
        $payload = $this->payload($when, 'MULTI_STOP', 2);
        $payload['stops'] = [
            ['text' => 'School', 'lat' => 25.60, 'lng' => 85.20],
            ['text' => 'Hall', 'lat' => 25.62, 'lng' => 85.25],
        ];
        $created = $this->bulk->create($customer, $payload);
        $this->assertGreaterThan(1, $created['stopCount']);
        $id = (int) $created['bulkId'];
        $this->bulk->confirm($id);
        $this->bulk->payAdvance($customer, (string) $id, ['method' => 'WALLET']);
        $assigned = $this->bulk->assign($id, [$a['driverId'], $b['driverId']]);
        $cabId = (string) $assigned['cars'][0]['cabId'];
        $this->bulk->activate($a['user'], $cabId, (string) $assigned['startOtp']);
        $this->bulk->depart($a['user'], $cabId);
        $mid = $this->bulk->completeStop($a['user'], $cabId);
        $this->assertSame('outbound', $mid['phase']);
        $this->assertSame(1, $mid['stopIndex']);
        $last = $this->bulk->completeStop($a['user'], $cabId, ['collected' => true]);
        $this->assertNotSame('COMPLETED', $this->bulk->show($customer, (string) $id)['status']);
        $this->bulk->completeStop($a['user'], $cabId, ['collected' => true]);
        $parent = $this->bulk->show($customer, (string) $id);
        $this->assertSame('completed', DB::table('bulk_booking_cabs')->where('id', $cabId)->value('phase'));
        $this->assertSame('LIVE', $parent['status']);
        $this->assertSame($last['stops'], $assigned['stops']);
    }

    private function payload(\Carbon\Carbon $when, string $kind, int $cabs = 5): array
    {
        return [
            'tripKind' => $kind,
            'category' => 'SEDAN',
            'vehicleCount' => $cabs,
            'lines' => [['category' => 'SEDAN', 'count' => $cabs]],
            'passengers' => $cabs * 2,
            'pickupText' => 'Patna Junction',
            'dropText' => 'Gaya Station',
            'pickupLat' => 25.5941,
            'pickupLng' => 85.1376,
            'dropLat' => 24.7955,
            'dropLng' => 84.9994,
            'eventDate' => $when->toDateString(),
            'eventTime' => $when->format('H:i'),
            'contactName' => 'Asha',
            'contactPhone' => '9800000000',
        ];
    }

    private function user(string $role): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => $role.' user',
            'phone' => '98'.random_int(10000000, 99999999),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * @return array{user: User, driverId: int}
     */
    private function driver(string $name, string $phone): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => $name,
            'phone' => $phone,
            'role' => 'DRIVER',
            'last_lat' => 25.60,
            'last_lng' => 85.14,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driverId = DB::table('drivers')->insertGetId([
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('vehicles')->insert([
            'driver_id' => $driverId,
            'category' => 'SEDAN',
            'registration_no' => 'BR01'.substr($phone, -4),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => User::query()->findOrFail($userId), 'driverId' => $driverId];
    }

    private function wallet(User $user, int $paise): void
    {
        DB::table('wallets')->updateOrInsert(
            ['owner_user_id' => $user->id, 'owner_type' => 'CUSTOMER'],
            ['balance_paise' => $paise, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function schema(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestamps();
        });
        Schema::create('drivers', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
        Schema::create('vehicles', function ($table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->string('category')->nullable();
            $table->string('registration_no')->nullable();
            $table->timestamps();
        });
        Schema::create('wallets', function ($table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->bigInteger('balance_paise')->default(0);
            $table->timestamps();
        });
        Schema::create('wallet_ledger', function ($table) {
            $table->id();
            $table->string('public_ref')->nullable();
            $table->string('payment_ref')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
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
        DB::table('bulk_rate_rules')->insert([
            ['category' => 'SEDAN', 'trip_kind' => 'ONE_WAY', 'per_vehicle_paise' => 100000, 'gst_percent' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['category' => 'SEDAN', 'trip_kind' => 'ROUND_WAY', 'per_vehicle_paise' => 180000, 'gst_percent' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['category' => 'SEDAN', 'trip_kind' => 'MULTI_STOP', 'per_vehicle_paise' => 150000, 'gst_percent' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
