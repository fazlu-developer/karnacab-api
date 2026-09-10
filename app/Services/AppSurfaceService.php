<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AppSurfaceService
{
    public function __construct(private readonly BookingService $bookings) {}

    public function experienceOverview(User $actor): array
    {
        $wallet = DB::table('wallets')->where('owner_user_id', $actor->id)->first();
        $balance = (int) ($wallet->balance_paise ?? 0);
        $coupons = Schema::hasTable('coupons')
            ? DB::table('coupons')->where('active', 1)->orderByDesc('id')->limit(20)->get()->map(fn ($row) => $this->presentCoupon($row))->all()
            : [];
        $notifications = Schema::hasTable('user_notifications')
            ? DB::table('user_notifications')->where('user_id', $actor->id)->orderByDesc('id')->limit(20)->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->title ?? $row->subject ?? 'Update',
                'body' => $row->body ?? $row->message ?? '',
                'read' => (bool) ($row->read_at ?? $row->is_read ?? false),
            ])->all()
            : [];
        $family = Schema::hasTable('family_members')
            ? DB::table('family_members')->where('user_id', $actor->id)->orderByDesc('id')->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'phone' => $row->phone,
                'relation' => $row->relation,
            ])->all()
            : [];
        $invoices = $this->invoices($actor)['invoices'];
        $parcels = Schema::hasTable('parcel_shipments')
            ? DB::table('parcel_shipments')->where('customer_id', $actor->id)->orderByDesc('id')->limit(40)->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'publicRef' => $row->public_ref,
                'status' => $row->status,
                'pickup' => $row->pickup_text,
                'drop' => $row->drop_text,
            ])->all()
            : [];

        return [
            'catalog' => $this->experienceCatalog(),
            'profile' => [
                'id' => (string) $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'phone' => $actor->phone,
                'address' => $actor->last_address,
                'stateId' => $actor->state_id,
                'districtId' => $actor->district_id,
                'role' => $actor->role,
            ],
            'emergency' => ['name' => $actor->emergency_name, 'phone' => $actor->emergency_phone],
            'wallet' => [
                'balancePaise' => $balance,
                'balanceRupees' => $balance / 100,
                'ownerType' => $wallet->owner_type ?? $actor->role,
            ],
            'coupons' => $coupons,
            'offers' => $coupons,
            'notifications' => $notifications,
            'unreadNotifications' => count(array_filter($notifications, fn ($row) => empty($row['read']))),
            'family' => $family,
            'invoices' => $invoices,
            'parcels' => $parcels,
        ];
    }

    public function experienceCatalog(): array
    {
        return [
            'tools' => ['profile', 'booking_history', 'wallet', 'coupons', 'notifications', 'family_booking'],
            'passengerFields' => ['passengerName', 'passengerMobile', 'pickup', 'destination', 'instructions'],
            'note' => 'The booker pays. The passenger is who the driver meets.',
        ];
    }

    public function previewCoupon(User $actor, array $data): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $farePaise = (int) ($data['farePaise'] ?? $data['discountPaise'] ?? 0);
        $row = Schema::hasTable('coupons') ? DB::table('coupons')->whereRaw('UPPER(code) = ?', [$code])->where('active', 1)->first() : null;
        if (! $row) {
            return ['ok' => false, 'message' => 'Coupon not found', 'discountPaise' => 0, 'payablePaise' => $farePaise];
        }
        $discount = (int) ($row->amount_paise ?? 0);
        if (($row->kind ?? 'percent') === 'percent') {
            $discount = (int) floor($farePaise * ((int) ($row->percent ?? 0)) / 100);
            $max = (int) ($row->max_discount_paise ?? 0);
            if ($max > 0) {
                $discount = min($discount, $max);
            }
        }
        $discount = min($discount, $farePaise);

        return [
            'ok' => true,
            'code' => $row->code,
            'title' => $row->title,
            'discountPaise' => $discount,
            'discountRupees' => $discount / 100,
            'payablePaise' => max(0, $farePaise - $discount),
            'message' => $row->subtitle ?: 'Coupon applied',
        ];
    }

    public function family(User $actor, Request $request)
    {
        if ($request->isMethod('post') && Schema::hasTable('family_members')) {
            $id = DB::table('family_members')->insertGetId([
                'user_id' => $actor->id,
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'relation' => $request->input('relation'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => (string) $id, 'ok' => true];
        }
        $members = Schema::hasTable('family_members')
            ? DB::table('family_members')->where('user_id', $actor->id)->orderByDesc('id')->get()
            : collect();

        return ['members' => $members];
    }

    public function invoices(User $actor): array
    {
        if (! Schema::hasTable('invoices')) {
            return ['invoices' => []];
        }
        $rows = DB::table('invoices')->where('customer_id', $actor->id)->orderByDesc('id')->limit(50)->get();

        return ['invoices' => $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'kind' => $row->kind,
            'status' => $row->status,
            'totalPaise' => (int) $row->total_paise,
            'totalRupees' => ((int) $row->total_paise) / 100,
            'currency' => $row->currency ?? 'INR',
            'issuedAt' => $row->issued_at,
        ])->all()];
    }

    public function supportFaqs(?string $audience = 'customer'): array
    {
        if (! Schema::hasTable('support_faqs')) {
            return ['faqs' => []];
        }
        $q = DB::table('support_faqs')->where('active', 1);
        if ($audience) {
            $q->where(function ($inner) use ($audience) {
                $inner->where('audience', $audience)->orWhere('audience', 'all');
            });
        }

        return ['faqs' => $q->orderBy('sort_order')->limit(100)->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'question' => $row->question,
            'answer' => $row->answer,
            'audience' => $row->audience,
        ])->all()];
    }

    public function supportTickets(User $actor, Request $request)
    {
        if ($request->isMethod('post') && Schema::hasTable('support_tickets')) {
            $id = DB::table('support_tickets')->insertGetId([
                'public_ref' => strtoupper(Str::random(8)),
                'user_id' => $actor->id,
                'kind' => $request->input('kind', 'support'),
                'status' => 'open',
                'subject' => $request->input('subject', 'Support'),
                'category' => $request->input('category', 'other'),
                'description' => $request->input('description', ''),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => (string) $id, 'ok' => true];
        }
        $tickets = Schema::hasTable('support_tickets')
            ? DB::table('support_tickets')->where('user_id', $actor->id)->orderByDesc('id')->limit(50)->get()
            : collect();

        return ['tickets' => $tickets];
    }

    public function adsServe(?string $placement = null): array
    {
        if (! Schema::hasTable('ad_campaigns')) {
            return ['ads' => [], 'placement' => $placement];
        }
        $rows = DB::table('ad_campaigns')->where('status', 'published')->orderByDesc('id')->limit(20)->get();

        return [
            'placement' => $placement,
            'ads' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'title' => $row->title ?? $row->name ?? 'Ad',
                'imageUrl' => $row->image_url ?? $row->creative_url ?? null,
                'clickUrl' => $row->click_url ?? $row->target_url ?? null,
                'placement' => $placement,
            ])->all(),
        ];
    }

    public function safetyCatalog(): array
    {
        return [
            'customer' => ['sos', 'emergency_contact', 'live_trip_sharing', 'driver_verification', 'support'],
            'driver' => ['sos', 'emergency_contact', 'support'],
            'types' => ['sos', 'complaint', 'lost_found'],
            'statuses' => ['open', 'reviewing', 'closed'],
        ];
    }

    public function safetyMe(User $actor): array
    {
        return [
            'emergency' => ['name' => $actor->emergency_name, 'phone' => $actor->emergency_phone],
            'helpline' => '112',
        ];
    }

    public function parcelCatalog(): array
    {
        $types = [
            ['key' => 'documents', 'label' => 'Documents'],
            ['key' => 'electronics', 'label' => 'Electronics'],
            ['key' => 'clothes', 'label' => 'Clothes'],
            ['key' => 'food', 'label' => 'Packed food'],
            ['key' => 'household', 'label' => 'Household'],
            ['key' => 'other', 'label' => 'Other permitted goods'],
        ];
        $prohibited = [
            ['key' => 'flammables', 'label' => 'Flammable liquids or gases'],
            ['key' => 'explosives', 'label' => 'Explosives'],
            ['key' => 'weapons', 'label' => 'Weapons or ammunition'],
            ['key' => 'drugs', 'label' => 'Illegal drugs'],
            ['key' => 'cash', 'label' => 'Cash, gold, or jewellery'],
            ['key' => 'live_animals', 'label' => 'Live animals'],
            ['key' => 'hazardous', 'label' => 'Hazardous chemicals'],
        ];
        $rules = Schema::hasTable('parcel_fare_rules')
            ? DB::table('parcel_fare_rules')->where('active', 1)->orderBy('lane')->get()
            : collect();

        return [
            'lanes' => [
                ['key' => 'LOCAL', 'label' => 'Local Parcel', 'biharLane' => false],
                ['key' => 'BIHAR', 'label' => 'Bihar Parcel', 'biharLane' => true],
            ],
            'types' => $types,
            'prohibited' => $prohibited,
            'vehicles' => $rules->pluck('category')->unique()->values()->all() ?: ['BIKE', 'SEDAN'],
            'fareRules' => $rules->map(fn ($row) => [
                'lane' => $row->lane,
                'category' => $row->category,
                'minKm' => (float) $row->min_km,
                'includedKm' => (float) $row->included_km,
                'perKmPaise' => (int) $row->per_km_paise,
                'extraKmPaise' => (int) $row->extra_km_paise,
                'perKgPaise' => (int) $row->per_kg_paise,
                'minChargePaise' => (int) $row->min_charge_paise,
                'gstPercent' => (int) $row->gst_percent,
            ])->all(),
            'tracking' => ['Created', 'Assigned', 'Picked Up', 'In Transit', 'Destination', 'Out for Delivery', 'Delivered'],
            'complianceText' => 'I confirm this parcel does not contain prohibited goods and complies with KarnaCab parcel policy.',
        ];
    }

    public function parcelQuote(array $data): array
    {
        $lane = ! empty($data['biharLane']) ? 'BIHAR' : 'LOCAL';
        $category = $data['category'] ?? 'BIKE';
        $km = max(1, (float) ($data['distanceKm'] ?? 1));
        $kg = max(0.1, (float) ($data['weightKg'] ?? 1));
        $rule = Schema::hasTable('parcel_fare_rules')
            ? DB::table('parcel_fare_rules')->where('active', 1)->where('lane', $lane)->where('category', $category)->first()
            : null;
        $minCharge = (int) ($rule->min_charge_paise ?? 4900);
        $included = (float) ($rule->included_km ?? 2);
        $perKm = (int) ($rule->per_km_paise ?? 1500);
        $extraKm = (int) ($rule->extra_km_paise ?? 1800);
        $perKg = (int) ($rule->per_kg_paise ?? 200);
        $gstPct = (int) ($rule->gst_percent ?? 5);
        $billedKm = max($km, (float) ($rule->min_km ?? 1));
        $extra = max(0, $billedKm - $included);
        $subtotal = $minCharge + (int) round($billedKm * $perKm) + (int) round($extra * $extraKm) + (int) round($kg * $perKg);
        $gst = (int) round($subtotal * $gstPct / 100);
        $total = $subtotal + $gst;

        return [
            'lane' => $lane,
            'category' => $category,
            'billedKm' => $billedKm,
            'weightKg' => $kg,
            'subtotalPaise' => $subtotal,
            'gstPaise' => $gst,
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'currency' => 'INR',
        ];
    }

    public function parcelsList(User $actor): array
    {
        if (! Schema::hasTable('parcel_shipments')) {
            return ['parcels' => []];
        }
        $q = DB::table('parcel_shipments')->orderByDesc('id');
        if ($actor->role === 'CUSTOMER' || $actor->role === 'CORPORATE') {
            $q->where('customer_id', $actor->id);
        } elseif ($actor->role === 'DRIVER') {
            $driver = Driver::query()->where('user_id', $actor->id)->first();
            $q->where(function ($inner) use ($driver) {
                $inner->where('status', 'created')->whereNull('driver_id');
                if ($driver) {
                    $inner->orWhere('driver_id', $driver->id);
                }
            });
        }

        return ['parcels' => $q->limit(100)->get()->map(fn ($row) => $this->presentParcel($row))->all()];
    }

    public function parcelCreate(User $actor, array $data): array
    {
        $quote = $this->parcelQuote($data);
        $id = DB::table('parcel_shipments')->insertGetId([
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'bihar_lane' => ! empty($data['biharLane']) ? 1 : 0,
            'parcel_type' => $data['parcelType'] ?? 'other',
            'description' => $data['description'] ?? null,
            'weight_kg' => $data['weightKg'] ?? 1,
            'length_cm' => $data['lengthCm'] ?? null,
            'width_cm' => $data['widthCm'] ?? null,
            'height_cm' => $data['heightCm'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'category' => $data['category'] ?? 'BIKE',
            'pickup_text' => $data['pickupText'] ?? 'Pickup',
            'drop_text' => $data['dropText'] ?? 'Drop',
            'pickup_lat' => $data['pickupLat'] ?? null,
            'pickup_lng' => $data['pickupLng'] ?? null,
            'drop_lat' => $data['dropLat'] ?? null,
            'drop_lng' => $data['dropLng'] ?? null,
            'distance_km' => $quote['billedKm'],
            'contact_name' => $data['contactName'] ?? $actor->name,
            'contact_phone' => $data['contactPhone'] ?? $actor->phone,
            'instructions' => $data['instructions'] ?? null,
            'compliance_confirmed' => ! empty($data['complianceConfirmed']) ? 1 : 0,
            'quote_paise' => $quote['totalPaise'],
            'quote_snapshot' => json_encode($quote),
            'payment_status' => 'unpaid',
            'pickup_otp' => (string) random_int(1000, 9999),
            'delivery_pin' => (string) random_int(1000, 9999),
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->presentParcel(DB::table('parcel_shipments')->where('id', $id)->first());
    }

    public function parcelOne(string $id): array
    {
        $row = DB::table('parcel_shipments')->where('id', $id)->first();
        abort_unless($row, 404);

        return $this->presentParcel($row);
    }

    public function parcelPay(string $id, array $data): array
    {
        DB::table('parcel_shipments')->where('id', $id)->update([
            'payment_status' => 'paid',
            'payment_method' => $data['method'] ?? $data['paymentMethod'] ?? 'demo',
            'updated_at' => now(),
        ]);

        return $this->parcelOne($id);
    }

    public function parcelAccept(User $actor, string $id): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        DB::table('parcel_shipments')->where('id', $id)->update([
            'driver_id' => $driver->id,
            'status' => 'assigned',
            'updated_at' => now(),
        ]);

        return $this->parcelOne($id);
    }

    public function parcelReject(string $id): array
    {
        return $this->parcelOne($id);
    }

    public function parcelLifecycle(string $id, string $action): array
    {
        $map = [
            'cancel' => 'cancelled',
            'pickup' => 'picked_up',
            'transit' => 'in_transit',
            'deliver' => 'delivered',
        ];
        $status = $map[strtolower($action)] ?? strtolower($action);
        DB::table('parcel_shipments')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);

        return $this->parcelOne($id);
    }

    public function travelCatalog(): array
    {
        $row = Schema::hasTable('system_settings') ? DB::table('system_settings')->where('key', 'travel_categories')->first() : null;
        $categories = [];
        if ($row?->value) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded)) {
                $categories = $decoded;
            }
        }
        if ($categories === []) {
            $categories = [
                ['key' => 'SIGHTSEEING', 'label' => 'Sightseeing'],
                ['key' => 'PILGRIMAGE', 'label' => 'Pilgrimage'],
            ];
        }

        return ['categories' => $categories, 'tracking' => ['Created', 'Confirmed']];
    }

    public function travelPackages(Request $request, ?string $id = null): array
    {
        if (! Schema::hasTable('travel_packages')) {
            abort_if((bool) $id, 404);

            return ['packages' => []];
        }
        if ($id) {
            $row = DB::table('travel_packages')->where('id', $id)->first();
            abort_unless($row, 404);

            return $this->presentTravelPackage($row);
        }
        $q = DB::table('travel_packages')->orderBy('id');
        if (Schema::hasColumn('travel_packages', 'status')) {
            $q->where('status', 'PUBLISHED');
        }
        if ($request->query('category')) {
            $q->where('category', $request->query('category'));
        }
        if ($request->query('destination')) {
            $q->where('destination', 'like', '%'.$request->query('destination').'%');
        }

        return ['packages' => $q->limit(100)->get()->map(fn ($row) => $this->presentTravelPackage($row))->all()];
    }

    public function travelBook(User $actor, array $data): array
    {
        $pkg = DB::table('travel_packages')->where('id', $data['packageId'] ?? $data['package_id'] ?? 0)->first();
        abort_unless($pkg, 404);
        $price = (int) ($pkg->price_paise ?? 0);
        $id = DB::table('travel_bookings')->insertGetId([
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'package_id' => $pkg->id,
            'travel_date' => $data['travelDate'] ?? now()->toDateString(),
            'guests' => (int) ($data['guests'] ?? 1),
            'contact_name' => $data['contactName'] ?? $actor->name,
            'contact_phone' => $data['contactPhone'] ?? $actor->phone,
            'notes' => $data['notes'] ?? null,
            'quote_paise' => $price,
            'payment_status' => 'unpaid',
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $row = DB::table('travel_bookings')->where('id', $id)->first();

        return $this->presentTravelBooking($row, $pkg);
    }

    public function travelPay(string $id, array $data): array
    {
        DB::table('travel_bookings')->where('id', $id)->update([
            'payment_status' => 'paid',
            'payment_method' => $data['method'] ?? $data['paymentMethod'] ?? 'demo',
            'status' => 'confirmed',
            'updated_at' => now(),
        ]);
        $row = DB::table('travel_bookings')->where('id', $id)->first();
        abort_unless($row, 404);
        $pkg = DB::table('travel_packages')->where('id', $row->package_id)->first();

        return $this->presentTravelBooking($row, $pkg);
    }

    public function bulkCatalog(): array
    {
        $events = [
            ['key' => 'WEDDING', 'label' => 'Wedding'],
            ['key' => 'CORPORATE', 'label' => 'Corporate'],
            ['key' => 'PILGRIMAGE', 'label' => 'Pilgrimage'],
        ];
        $row = Schema::hasTable('system_settings') ? DB::table('system_settings')->where('key', 'bulk_event_types')->first() : null;
        if ($row?->value) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded) && $decoded) {
                $events = $decoded;
            }
        }

        return [
            'events' => $events,
            'vehicles' => ['SEDAN', 'SUV', 'TRAVELLER'],
            'advancePercent' => 30,
            'tracking' => ['Request', 'Quotation', 'Accepted', 'Advance', 'Assignment', 'Trip', 'Final Invoice'],
        ];
    }

    public function bulkQuote(array $data): array
    {
        $vehicles = max(1, (int) ($data['vehicles'] ?? 1));
        $per = match ($data['category'] ?? 'TRAVELLER') {
            'SEDAN' => 250000,
            'SUV' => 350000,
            default => 450000,
        };
        $subtotal = $per * $vehicles;
        $gst = (int) round($subtotal * 0.05);
        $total = $subtotal + $gst;

        return [
            'vehicles' => $vehicles,
            'category' => $data['category'] ?? 'TRAVELLER',
            'subtotalPaise' => $subtotal,
            'gstPaise' => $gst,
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'advancePaise' => (int) round($total * 0.3),
        ];
    }

    public function bulkCreate(User $actor, array $data): array
    {
        $quote = $this->bulkQuote($data);
        $payload = [
            'public_ref' => strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('bulk_bookings', 'event_key')) {
            $payload['event_key'] = $data['eventKey'] ?? 'WEDDING';
        }
        if (Schema::hasColumn('bulk_bookings', 'category')) {
            $payload['category'] = $data['category'] ?? 'TRAVELLER';
        }
        if (Schema::hasColumn('bulk_bookings', 'quote_paise')) {
            $payload['quote_paise'] = $quote['totalPaise'];
        }
        $id = DB::table('bulk_bookings')->insertGetId($payload);

        return ['id' => (string) $id, 'ok' => true, 'quote' => $quote];
    }

    public function walletMe(User $actor): array
    {
        $row = DB::table('wallets')->where('owner_user_id', $actor->id)->first();
        $balance = (int) ($row->balance_paise ?? 0);

        return [
            'balancePaise' => $balance,
            'balanceRupees' => $balance / 100,
            'ownerType' => $row->owner_type ?? $actor->role,
        ];
    }

    public function presentPlace($row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'address' => $row->address,
            'lat' => (float) $row->lat,
            'lng' => (float) $row->lng,
        ];
    }

    public function presentParcel(object $row): array
    {
        $total = (int) ($row->quote_paise ?? 0);

        return [
            'id' => (string) $row->id,
            'kind' => 'parcel',
            'publicRef' => $row->public_ref,
            'customerId' => (string) $row->customer_id,
            'status' => $row->status,
            'biharLane' => (bool) $row->bihar_lane,
            'parcelType' => $row->parcel_type,
            'category' => $row->category,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickup' => $row->pickup_text,
            'destination' => $row->drop_text,
            'quotePaise' => $total,
            'quoteRupees' => $total / 100,
            'paymentStatus' => $row->payment_status,
            'pickupOtp' => $row->pickup_otp,
            'deliveryPin' => $row->delivery_pin,
            'driverId' => $row->driver_id ? (string) $row->driver_id : null,
            'fare' => ['totalPaise' => $total, 'totalRupees' => $total / 100, 'currency' => 'INR'],
            'billedKm' => (float) ($row->distance_km ?? 0),
        ];
    }

    private function presentCoupon(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'code' => $row->code,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'kind' => $row->kind,
            'percent' => $row->percent,
        ];
    }

    private function presentTravelPackage(object $row): array
    {
        $price = (int) ($row->price_paise ?? 0);
        $dates = $row->available_dates ?? [];
        if (is_string($dates)) {
            $dates = json_decode($dates, true) ?: [];
        }

        return [
            'id' => (int) $row->id,
            'kind' => 'travel',
            'category' => $row->category,
            'title' => $row->title,
            'destination' => $row->destination,
            'places' => $row->places,
            'durationHours' => $row->duration_hours,
            'durationLabel' => $row->duration_label ?: (($row->duration_hours ?? 0).' hours'),
            'kmIncluded' => $row->km_included,
            'vehicleLabel' => $row->vehicle_label,
            'driverLabel' => $row->driver_label ?? 'Dedicated driver',
            'pricePaise' => $price,
            'priceRupees' => $price / 100,
            'inclusions' => $row->inclusions ?? '',
            'exclusions' => $row->exclusions ?? '',
            'availableDates' => $dates,
            'status' => $row->status ?? 'PUBLISHED',
        ];
    }

    private function presentTravelBooking(object $row, ?object $pkg): array
    {
        $total = (int) ($row->quote_paise ?? 0);

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'status' => $row->status,
            'paymentStatus' => $row->payment_status,
            'guests' => $row->guests,
            'travelDate' => $row->travel_date,
            'package' => $pkg ? $this->presentTravelPackage($pkg) : null,
            'fare' => ['totalPaise' => $total, 'totalRupees' => $total / 100, 'currency' => 'INR'],
        ];
    }
}
