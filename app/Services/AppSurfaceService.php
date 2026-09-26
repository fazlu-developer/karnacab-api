<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\User;
use App\Support\BulkSchema;
use App\Support\CorporatePlanSchema;
use App\Support\Geo;
use App\Support\TravelPackageSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AppSurfaceService
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly FareService $fares,
    ) {}

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
        abort_unless($row, 422, 'Coupon not found');
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
        $bookings = collect();
        if (Schema::hasTable('bookings')) {
            $q = DB::table('bookings')->whereIn('status', ['COMPLETED', 'TRIP_COMPLETED', 'completed']);
            if ($actor->role === 'DRIVER') {
                $driverId = Driver::query()->where('user_id', $actor->id)->value('id');
                $q->where('driver_id', $driverId ?: 0);
            } else {
                $q->where('customer_id', $actor->id);
            }
            $bookings = $q->orderByDesc('id')->limit(50)->get();
        }

        $fromBookings = $bookings->map(fn ($row) => $this->presentInvoice($actor, $row))->all();
        if ($fromBookings !== []) {
            return ['invoices' => $fromBookings];
        }
        if (! Schema::hasTable('invoices')) {
            return ['invoices' => []];
        }
        $rows = $actor->role === 'DRIVER'
            ? collect()
            : DB::table('invoices')->where('customer_id', $actor->id)->orderByDesc('id')->limit(50)->get();

        return ['invoices' => $rows->map(fn ($row) => $this->presentInvoice($actor, $row))->all()];
    }

    public function invoiceOne(User $actor, string $id): array
    {
        $rows = $this->invoices($actor)['invoices'];
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === $id || (string) ($row['bookingId'] ?? '') === $id || (string) ($row['publicRef'] ?? '') === $id) {
                return ['invoice' => $row];
            }
        }
        abort(404, 'Invoice not found');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(User $actor, object $row): array
    {
        $snapshot = $row->quote_snapshot ?? null;
        if (is_string($snapshot)) {
            $decoded = json_decode($snapshot, true);
            $snapshot = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($snapshot)) {
            $snapshot = [];
        }
        $breakdown = $snapshot['final']['breakdown'] ?? $snapshot['breakdown'] ?? [];
        $totalPaise = (int) ($row->total_paise ?? $row->final_fare_paise ?? $row->quote_paise ?? 0);
        $gstPaise = (int) ($breakdown['gstPaise'] ?? $row->gst_paise ?? 0);
        $basePaise = max(0, $totalPaise - $gstPaise);
        $commissionPaise = (int) ($row->commission_paise ?? $snapshot['commissionPaise'] ?? 0);
        $earningPaise = (int) ($row->driver_earning_paise ?? $snapshot['driverEarningPaise'] ?? max(0, $totalPaise - $commissionPaise));

        return [
            'id' => (string) ($row->id ?? ''),
            'bookingId' => (string) ($row->booking_id ?? $row->id ?? ''),
            'publicRef' => $row->public_ref ?? ('INV'.($row->id ?? '')),
            'invoiceNumber' => $row->public_ref ?? ('INV'.($row->id ?? '')),
            'kind' => $row->kind ?? $row->product ?? 'ride',
            'product' => $row->product ?? 'RIDE',
            'category' => $row->category ?? null,
            'status' => $row->status ?? 'paid',
            'paymentMode' => $row->payment_mode ?? 'CASH',
            'paymentStatus' => $row->payment_status ?? $row->status ?? 'paid',
            'totalPaise' => $totalPaise,
            'totalRupees' => $totalPaise / 100,
            'gstPaise' => $gstPaise,
            'gstRupees' => $gstPaise / 100,
            'subtotalPaise' => $basePaise,
            'subtotalRupees' => $basePaise / 100,
            'commissionPaise' => $commissionPaise,
            'commissionRupees' => $commissionPaise / 100,
            'driverEarningPaise' => $earningPaise,
            'driverEarningRupees' => $earningPaise / 100,
            'currency' => $row->currency ?? 'INR',
            'issuedAt' => $row->issued_at ?? $row->trip_ended_at ?? $row->updated_at ?? $row->created_at,
            'pickup' => $row->pickup_text ?? $row->pickup ?? null,
            'drop' => $row->drop_text ?? $row->drop ?? null,
            'distanceKm' => $row->actual_distance_km ?? $row->distance_km ?? null,
            'customerName' => $actor->role === 'DRIVER' ? ($row->passenger_name ?? 'Customer') : $actor->name,
            'customerPhone' => $actor->role === 'DRIVER' ? ($row->passenger_phone ?? null) : $actor->phone,
            'customerEmail' => $actor->role === 'DRIVER' ? null : $actor->email,
            'driverName' => $actor->role === 'DRIVER' ? $actor->name : null,
            'company' => 'KarnaCab',
            'companyAddress' => 'KarnaCab Mobility Pvt Ltd, India',
            'gstin' => 'GSTIN applied as per fare rules',
        ];
    }

    public function supportFaqs(?string $audience = 'customer'): array
    {
        $this->ensureFaqs();
        if (! Schema::hasTable('support_faqs')) {
            return ['faqs' => $this->defaultFaqs()];
        }
        $q = DB::table('support_faqs')->where('active', 1);
        if ($audience && Schema::hasColumn('support_faqs', 'audience')) {
            $q->where(function ($inner) use ($audience) {
                $inner->where('audience', $audience)->orWhere('audience', 'all');
            });
        }
        $rows = $q->orderBy(Schema::hasColumn('support_faqs', 'sort_order') ? 'sort_order' : 'id')->limit(100)->get();
        if ($rows->isEmpty()) {
            return ['faqs' => $this->defaultFaqs()];
        }

        return ['faqs' => $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'question' => $row->question,
            'answer' => $row->answer,
            'audience' => $row->audience ?? 'customer',
        ])->all()];
    }

    public function supportTickets(User $actor, Request $request)
    {
        if ($request->isMethod('post') && Schema::hasTable('support_tickets')) {
            $kind = (string) ($request->input('kind') ?? $request->input('type') ?? 'support');
            if ($kind === 'lost_found') {
                $kind = 'complaint';
            }
            $subject = (string) ($request->input('subject') ?: match ($kind) {
                'complaint' => 'Complaint',
                'parcel' => 'Parcel',
                default => 'Support',
            });
            if (($request->input('type') ?? '') === 'lost_found') {
                $subject = 'Lost & found';
            }
            $description = (string) ($request->input('description') ?? $request->input('message') ?? '');
            $payload = [
                'public_ref' => strtoupper(Str::random(8)),
                'user_id' => $actor->id,
                'kind' => $kind,
                'status' => 'open',
                'subject' => $subject,
                'category' => $request->input('category') ?: (($request->input('type') === 'lost_found') ? 'lost_found' : 'other'),
                'description' => $description,
                'message' => $description,
                'type' => $request->input('type') ?? $kind,
                'booking_id' => $request->input('bookingId') ?? $request->input('booking_id'),
                'priority' => 'medium',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $id = DB::table('support_tickets')->insertGetId(array_filter(
                $payload,
                fn ($key) => Schema::hasColumn('support_tickets', $key),
                ARRAY_FILTER_USE_KEY,
            ));
            if (Schema::hasColumn('support_tickets', 'district_id') && $actor->district_id) {
                DB::table('support_tickets')->where('id', $id)->update(['district_id' => $actor->district_id]);
            }
            $this->addSupportMessage((int) $id, (int) $actor->id, $description, false);

            return [
                'id' => (string) $id,
                'ok' => true,
                'incidentId' => (string) $id,
                'publicRef' => $payload['public_ref'],
            ];
        }
        $tickets = Schema::hasTable('support_tickets')
            ? DB::table('support_tickets')->where('user_id', $actor->id)->orderByDesc('id')->limit(50)->get()
            : collect();

        return ['tickets' => $tickets->map(fn ($row) => [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref ?? null,
            'subject' => $row->subject ?? $row->type ?? 'Ticket',
            'status' => $row->status ?? 'open',
            'kind' => $row->kind ?? $row->type ?? 'support',
            'description' => $row->description ?? $row->message ?? '',
            'message' => $row->description ?? $row->message ?? $row->subject ?? '',
        ])->all()];
    }

    public function supportTicket(User $actor, string $id): array
    {
        $row = DB::table('support_tickets')->where('id', $id)->where('user_id', $actor->id)->first();
        abort_unless($row, 404, 'Ticket not found');

        return $this->presentSupportTicket($row);
    }

    public function supportTicketMessage(User $actor, string $id, string $body): array
    {
        $row = DB::table('support_tickets')->where('id', $id)->where('user_id', $actor->id)->first();
        abort_unless($row, 404, 'Ticket not found');
        abort_unless(trim($body) !== '', 422, 'Write a message');
        abort_if(in_array((string) $row->status, ['resolved', 'closed'], true), 422, 'This ticket is already closed');
        $this->addSupportMessage((int) $row->id, (int) $actor->id, trim($body), false);

        return $this->presentSupportTicket(DB::table('support_tickets')->where('id', $row->id)->first());
    }

    private function presentSupportTicket(object $row): array
    {
        $messages = Schema::hasTable('support_messages')
            ? DB::table('support_messages')->where('ticket_id', $row->id)->orderBy('id')->get()->map(fn ($message) => [
                'id' => (string) $message->id,
                'body' => $message->body,
                'fromStaff' => (bool) ($message->from_staff ?? false),
                'createdAt' => $message->created_at ?? null,
            ])->all()
            : [];

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref ?? null,
            'subject' => $row->subject ?? 'Ticket',
            'status' => $row->status ?? 'open',
            'kind' => $row->kind ?? 'support',
            'description' => $row->description ?? $row->message ?? '',
            'messages' => $messages,
        ];
    }

    private function addSupportMessage(int $ticketId, int $authorId, string $body, bool $staff): void
    {
        if ($body === '' || ! Schema::hasTable('support_messages')) {
            return;
        }
        DB::table('support_messages')->insert([
            'ticket_id' => $ticketId,
            'author_id' => $authorId,
            'from_staff' => $staff ? 1 : 0,
            'body' => substr($body, 0, 2000),
            'created_at' => now(),
        ]);
    }

    public function safetyIncidents(User $actor): array
    {
        if (! Schema::hasTable('support_tickets')) {
            return ['incidents' => []];
        }
        $rows = DB::table('support_tickets')->where('user_id', $actor->id)->orderByDesc('id')->limit(40)->get();

        return ['incidents' => $rows->map(fn ($row) => [
            'incidentId' => $row->public_ref ?? (string) $row->id,
            'id' => (string) $row->id,
            'type' => $row->kind ?? $row->category ?? 'support',
            'status' => $row->status ?? 'open',
            'description' => $row->description ?? $row->message ?? $row->subject ?? '',
        ])->all()];
    }

    public function markNotificationRead(User $actor, string $id): array
    {
        if (Schema::hasTable('user_notifications')) {
            if (! Schema::hasColumn('user_notifications', 'read_at')) {
                try {
                    Schema::table('user_notifications', fn ($table) => $table->timestamp('read_at')->nullable());
                } catch (\Throwable) {
                }
            }
            $q = DB::table('user_notifications')->where('user_id', $actor->id)->where('id', $id);
            if (Schema::hasColumn('user_notifications', 'read_at')) {
                $q->update(['read_at' => now()]);
            } elseif (Schema::hasColumn('user_notifications', 'is_read')) {
                $q->update(['is_read' => 1]);
            }
        }

        return $this->experienceOverview($actor);
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
        $category = strtoupper((string) ($data['category'] ?? 'BIKE'));
        $km = max(1, (float) ($data['distanceKm'] ?? 1));
        $kg = max(0.1, (float) ($data['weightKg'] ?? 1));
        $aliases = ['CAR' => 'SEDAN', 'VAN' => 'TRAVELLER', 'MINI' => 'SEDAN'];
        $category = $aliases[$category] ?? $category;
        $baseQuery = Schema::hasTable('parcel_fare_rules') ? DB::table('parcel_fare_rules')->where('active', 1) : null;
        $rule = null;
        if ($baseQuery) {
            $rule = (clone $baseQuery)->where('lane', $lane)->where('category', $category)->first()
                ?: (clone $baseQuery)->where('category', $category)->first()
                ?: (clone $baseQuery)->where('lane', $lane)->first()
                ?: (clone $baseQuery)->orderBy('id')->first();
        }
        $minCharge = (int) ($rule->min_charge_paise ?? 4900);
        $included = (float) ($rule->included_km ?? 2);
        $perKm = (int) ($rule->per_km_paise ?? 1500);
        $extraKm = (int) ($rule->extra_km_paise ?? 1800);
        $perKg = (int) ($rule->per_kg_paise ?? 200);
        $gstPct = (int) ($rule->gst_percent ?? 5);
        $billedKm = max($km, (float) ($rule->min_km ?? 1));
        $extra = max(0, $billedKm - $included);
        $distancePaise = (int) round($billedKm * $perKm) + (int) round($extra * $extraKm);
        $weightPaise = (int) round($kg * $perKg);
        $basePaise = $minCharge + $distancePaise;
        $insurancePaise = ! empty($data['insurance']) ? max(2000, (int) round($perKg * max(1, $kg))) : 0;
        $fragilePaise = ! empty($data['fragile']) ? max(1000, (int) round($perKg * 2)) : 0;
        $handlingPaise = $insurancePaise + $fragilePaise;
        $subtotal = $basePaise + $weightPaise + $handlingPaise;
        $gst = (int) round($subtotal * $gstPct / 100);
        $total = $subtotal + $gst;

        return [
            'lane' => $lane,
            'category' => $category,
            'billedKm' => $billedKm,
            'weightKg' => $kg,
            'basePaise' => $basePaise,
            'weightPaise' => $weightPaise,
            'handlingPaise' => $handlingPaise,
            'insurancePaise' => $insurancePaise,
            'fragilePaise' => $fragilePaise,
            'subtotalPaise' => $subtotal,
            'gstPaise' => $gst,
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'currency' => 'INR',
            'source' => $rule ? 'server' : 'fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{options: list<array<string, mixed>>}
     */
    public function parcelQuoteOptions(array $data): array
    {
        $fromRules = Schema::hasTable('parcel_fare_rules')
            ? DB::table('parcel_fare_rules')->where('active', 1)->pluck('category')->unique()->values()->all()
            : [];
        $categories = $fromRules ?: ['BIKE', 'AUTO', 'SEDAN', 'TRAVELLER', 'TRUCK'];
        $options = [];
        foreach ($categories as $category) {
            $options[] = $this->parcelQuote([...$data, 'category' => (string) $category]);
        }

        return ['options' => $options];
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
            'instructions' => trim(implode('. ', array_filter([
                $data['instructions'] ?? null,
                ! empty($data['insurance']) ? 'Insurance requested' : null,
                ! empty($data['fragile']) ? 'Fragile handling' : null,
                ! empty($data['scheduledAt']) ? 'Scheduled pickup: '.$data['scheduledAt'] : null,
            ]))) ?: null,
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

    public function parcelPay(User $actor, string $id, array $data): array
    {
        $row = DB::table('parcel_shipments')->where('id', $id)->first();
        abort_unless($row, 404, 'Parcel not found');
        $method = strtoupper((string) ($data['method'] ?? $data['paymentMethod'] ?? 'CASH'));
        if (str_contains($method, 'WALLET')) {
            app(CustomerWallet::class)->debit((int) $actor->id, (int) ($row->quote_paise ?? 0), 'Parcel fare', null, 'parcel');
        }
        DB::table('parcel_shipments')->where('id', $id)->update([
            'payment_status' => 'paid',
            'payment_method' => $method,
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
            'picked_up' => 'picked_up',
            'transit' => 'in_transit',
            'in_transit' => 'in_transit',
            'arrive' => 'destination',
            'destination' => 'destination',
            'out_for_delivery' => 'out_for_delivery',
            'deliver' => 'delivered',
            'delivered' => 'delivered',
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
                ['key' => 'SIGHTSEEING', 'label' => 'Local Sightseeing'],
                ['key' => 'OUTSTATION', 'label' => 'Outstation Tour'],
                ['key' => 'WEEKEND', 'label' => 'Weekend Getaway'],
                ['key' => 'PILGRIMAGE', 'label' => 'Pilgrimage Tour'],
                ['key' => 'CORPORATE', 'label' => 'Corporate Travel'],
                ['key' => 'PACKAGES', 'label' => 'Travel Packages'],
            ];
        }

        return ['categories' => $categories, 'tracking' => ['Created', 'Confirmed']];
    }

    public function travelPackages(Request $request, ?string $id = null): array
    {
        TravelPackageSchema::ensure();
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
        if ($request->query('origin')) {
            $q->where('origin', 'like', '%'.$request->query('origin').'%');
        }
        if ($request->query('region')) {
            $q->where('region', $request->query('region'));
        }

        return ['packages' => $q->limit(100)->get()->map(fn ($row) => $this->presentTravelPackage($row))->all()];
    }

    public function travelBook(User $actor, array $data): array
    {
        $pkg = DB::table('travel_packages')->where('id', $data['packageId'] ?? $data['package_id'] ?? 0)->first();
        abort_unless($pkg, 404);
        $guests = max(1, (int) ($data['guests'] ?? 1));
        $unit = (int) ($pkg->price_paise ?? 0);
        $base = $unit * $guests;
        $gst = (int) round($base * 0.05);
        $total = $base + $gst;
        $id = DB::table('travel_bookings')->insertGetId(array_filter([
            'public_ref' => 'KTR'.strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'package_id' => $pkg->id,
            'travel_date' => $data['travelDate'] ?? now()->toDateString(),
            'guests' => $guests,
            'contact_name' => $data['contactName'] ?? $actor->name,
            'contact_phone' => $data['contactPhone'] ?? $actor->phone,
            'pickup_text' => $data['pickupText'] ?? null,
            'notes' => $data['notes'] ?? null,
            'quote_paise' => $total,
            'payment_status' => 'unpaid',
            'status' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('travel_bookings', $key), ARRAY_FILTER_USE_KEY));
        $row = DB::table('travel_bookings')->where('id', $id)->first();

        return $this->presentTravelBooking($row, $pkg);
    }

    public function travelPay(User $actor, string $id, array $data): array
    {
        $row = DB::table('travel_bookings')->where('id', $id)->first();
        abort_unless($row, 404);
        $method = strtoupper((string) ($data['method'] ?? $data['paymentMethod'] ?? 'CASH'));
        if (str_contains($method, 'WALLET')) {
            app(CustomerWallet::class)->debit((int) $actor->id, (int) ($row->quote_paise ?? 0), 'Travel package', null, 'travel');
        }
        DB::table('travel_bookings')->where('id', $id)->update([
            'payment_status' => 'paid',
            'payment_method' => $method,
            'status' => 'confirmed',
            'updated_at' => now(),
        ]);
        $row = DB::table('travel_bookings')->where('id', $id)->first();
        $pkg = DB::table('travel_packages')->where('id', $row->package_id)->first();
        $rideId = $this->attachTravelRideBooking($actor, $row, $pkg);
        if ($rideId && Schema::hasColumn('travel_bookings', 'ride_booking_id')) {
            DB::table('travel_bookings')->where('id', $id)->update(['ride_booking_id' => $rideId, 'updated_at' => now()]);
            $row = DB::table('travel_bookings')->where('id', $id)->first();
        }

        return $this->presentTravelBooking($row, $pkg);
    }

    public function bulkCatalog(): array
    {
        BulkSchema::ensure();
        $events = [
            ['key' => 'CORPORATE', 'label' => 'Corporate Events'],
            ['key' => 'EMPLOYEE', 'label' => 'Employee Transport'],
            ['key' => 'WEDDING', 'label' => 'Wedding Events'],
            ['key' => 'SCHOOL', 'label' => 'School & College Trips'],
            ['key' => 'TOUR', 'label' => 'Tour Groups'],
        ];
        $row = Schema::hasTable('system_settings') ? DB::table('system_settings')->where('key', 'bulk_event_types')->first() : null;
        if ($row?->value) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded) && $decoded) {
                $events = $decoded;
            }
        }
        $advance = 30;
        $advRow = Schema::hasTable('system_settings') ? DB::table('system_settings')->where('key', 'bulk_advance_percent')->first() : null;
        if ($advRow?->value !== null && is_numeric($advRow->value)) {
            $advance = (int) $advRow->value;
        }
        $options = [];
        foreach ($this->bulkVehicleMeta() as $meta) {
            $unit = $this->bulkUnitQuote($meta['key'], [
                'eventKey' => 'CORPORATE',
                'tripKind' => 'ONE_WAY',
            ]);
            if (($unit['perCabPaise'] ?? 0) <= 0) {
                continue;
            }
            $options[] = array_merge($meta, $unit);
        }

        return [
            'events' => $events,
            'vehicles' => array_column($options, 'key') ?: ['SEDAN', 'SUV', 'TRAVELLER'],
            'options' => $options,
            'advancePercent' => $advance,
            'tracking' => ['Request', 'Quotation', 'Accepted', 'Advance', 'Assignment', 'Trip', 'Final Invoice'],
        ];
    }

    public function bulkQuote(array $data): array
    {
        BulkSchema::ensure();
        $lines = $this->bulkLinesFrom($data);
        $quoted = [];
        $base = 0;
        $allow = 0;
        $toll = 0;
        $gst = 0;
        $cabs = 0;
        foreach ($lines as $line) {
            $count = max(0, (int) ($line['count'] ?? 0));
            if ($count < 1) {
                continue;
            }
            $unit = $this->bulkUnitQuote((string) $line['category'], $data);
            $per = (int) ($unit['perCabPaise'] ?? 0);
            if ($per <= 0) {
                continue;
            }
            $lineBase = $per * $count;
            $lineAllow = ((int) ($unit['driverAllowPaise'] ?? 0)) * $count;
            $lineToll = ((int) ($unit['tollPaise'] ?? 0)) * $count;
            $lineGst = ((int) ($unit['gstPaise'] ?? 0)) * $count;
            $lineTotal = $lineBase + $lineAllow + $lineToll + $lineGst;
            $quoted[] = array_merge($unit, [
                'category' => $unit['category'] ?? $line['category'],
                'count' => $count,
                'lineBasePaise' => $lineBase,
                'lineTotalPaise' => $lineTotal,
                'lineTotalRupees' => $lineTotal / 100,
            ]);
            $base += $lineBase;
            $allow += $lineAllow;
            $toll += $lineToll;
            $gst += $lineGst;
            $cabs += $count;
        }
        $options = [];
        foreach ($this->bulkVehicleMeta() as $meta) {
            $unit = $this->bulkUnitQuote($meta['key'], $data);
            if (($unit['perCabPaise'] ?? 0) <= 0) {
                continue;
            }
            $options[] = array_merge($meta, $unit);
        }
        $total = $base + $allow + $toll + $gst;
        $advance = 30;
        $advRow = Schema::hasTable('system_settings') ? DB::table('system_settings')->where('key', 'bulk_advance_percent')->first() : null;
        if ($advRow?->value !== null && is_numeric($advRow->value)) {
            $advance = (int) $advRow->value;
        }

        return [
            'tripKind' => $this->bulkTripKind($data),
            'vehicles' => max(1, $cabs),
            'vehicleCount' => $cabs,
            'lines' => $quoted,
            'options' => $options,
            'basePaise' => $base,
            'driverAllowPaise' => $allow,
            'tollPaise' => $toll,
            'gstPaise' => $gst,
            'subtotalPaise' => $base + $allow + $toll,
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'advancePaise' => (int) round($total * ($advance / 100)),
            'advanceRupees' => round($total * ($advance / 100)) / 100,
            'currency' => 'INR',
        ];
    }

    public function bulkCreate(User $actor, array $data): array
    {
        BulkSchema::ensure();
        $quote = $this->bulkQuote($data);
        $lines = $quote['lines'] ?? [];
        $cabs = max(1, (int) ($quote['vehicleCount'] ?? 1));
        $method = strtoupper((string) ($data['paymentMethod'] ?? $data['method'] ?? 'CASH'));
        $ref = 'BLK'.random_int(1000000, 9999999);
        $payload = [
            'public_ref' => $ref,
            'customer_id' => $actor->id,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $map = [
            'event_key' => $data['eventKey'] ?? 'CORPORATE',
            'category' => $data['category'] ?? ($lines[0]['category'] ?? 'SEDAN'),
            'trip_kind' => $quote['tripKind'] ?? 'ONE_WAY',
            'quote_paise' => $quote['totalPaise'] ?? 0,
            'pickup_text' => $data['pickupText'] ?? null,
            'drop_text' => $data['dropText'] ?? null,
            'pickup_lat' => $data['pickupLat'] ?? null,
            'pickup_lng' => $data['pickupLng'] ?? null,
            'drop_lat' => $data['dropLat'] ?? null,
            'drop_lng' => $data['dropLng'] ?? null,
            'event_date' => $data['eventDate'] ?? null,
            'event_time' => $data['eventTime'] ?? null,
            'vehicle_count' => $cabs,
            'passengers' => $data['passengers'] ?? null,
            'passengers_per_cab' => $data['passengersPerCab'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'lines_json' => json_encode($lines),
            'quote_snapshot' => json_encode($quote),
            'payment_method' => $method,
            'payment_status' => 'paid',
            'contact_name' => $data['contactName'] ?? $actor->name,
            'contact_phone' => $data['contactPhone'] ?? $actor->phone,
        ];
        foreach ($map as $col => $value) {
            if (Schema::hasColumn('bulk_bookings', $col)) {
                $payload[$col] = $value;
            }
        }
        $id = DB::table('bulk_bookings')->insertGetId($payload);
        $row = DB::table('bulk_bookings')->where('id', $id)->first();
        $rideId = $this->attachBulkRideBooking($actor, $row, $quote, $data);
        if ($rideId && Schema::hasColumn('bulk_bookings', 'ride_booking_id')) {
            DB::table('bulk_bookings')->where('id', $id)->update(['ride_booking_id' => $rideId, 'updated_at' => now()]);
            $row = DB::table('bulk_bookings')->where('id', $id)->first();
        }

        return $this->presentBulkBooking($row, $quote, $actor);
    }

    public function corporateCatalog(): array
    {
        CorporatePlanSchema::ensure();
        $options = [];
        foreach ([
            ['key' => 'SEDAN', 'label' => 'Sedan', 'seats' => 4, 'blurb' => 'AC, Comfortable'],
            ['key' => 'SUV', 'label' => 'SUV', 'seats' => 6, 'blurb' => 'Spacious & Premium'],
            ['key' => 'TRAVELLER', 'label' => 'Traveller (12 Seater)', 'seats' => 12, 'blurb' => 'Best for team travel'],
        ] as $meta) {
            $unit = $this->bulkUnitQuote($meta['key'], ['tripKind' => 'ONE_WAY', 'eventKey' => 'CORPORATE']);
            if (($unit['perCabPaise'] ?? 0) <= 0) {
                continue;
            }
            $options[] = array_merge($meta, $unit);
        }

        return [
            'categories' => [
                ['key' => 'EMPLOYEE', 'label' => 'Employee Transport', 'icon' => 'groups'],
                ['key' => 'EXECUTIVE', 'label' => 'Executive Travel', 'icon' => 'person'],
                ['key' => 'CLIENT', 'label' => 'Client Visit Travel', 'icon' => 'handshake'],
                ['key' => 'AIRPORT', 'label' => 'Airport Transfers', 'icon' => 'flight'],
                ['key' => 'OUTSTATION', 'label' => 'Outstation Travel', 'icon' => 'map'],
                ['key' => 'EVENT', 'label' => 'Event Transportation', 'icon' => 'event'],
            ],
            'purposes' => ['Client Meeting', 'Employee Transport', 'Executive Travel', 'Airport Transfer', 'Outstation', 'Event'],
            'options' => $options,
            'plans' => $this->corporatePlans(),
            'benefits' => [
                'GST Invoices & Reports',
                'Dedicated Account Manager',
                'Priority Booking & Support',
                'Flexible Payment Options',
                'Corporate Dashboard',
                'Travel Policy & Approval System',
            ],
        ];
    }

    public function corporateQuote(array $data): array
    {
        CorporatePlanSchema::ensure();
        $category = strtoupper((string) ($data['category'] ?? 'SEDAN'));
        $vehicle = $this->bulkUnitQuote($category, $data);
        $options = [];
        foreach (['SEDAN', 'SUV', 'TRAVELLER'] as $key) {
            $unit = $this->bulkUnitQuote($key, $data);
            if (($unit['perCabPaise'] ?? 0) <= 0) {
                continue;
            }
            $options[] = $unit;
        }
        $plan = $this->resolveCorporatePlan($data);
        $vehicleBase = (int) ($vehicle['perCabPaise'] ?? 0);
        $allow = (int) ($vehicle['driverAllowPaise'] ?? 0);
        $toll = (int) ($vehicle['tollPaise'] ?? 0);
        $mode = strtoupper((string) ($plan['pricingMode'] ?? 'VEHICLE'));
        $base = $vehicleBase;
        if ($mode === 'FIXED' && (int) ($plan['pricePaise'] ?? 0) > 0) {
            $base = (int) $plan['pricePaise'];
        }
        $gstPct = (float) ($plan['gstPercent'] ?? 5);
        $gst = (int) round(($base + $allow) * ($gstPct / 100));
        $total = $base + $allow + $toll + $gst;

        return [
            'tripKind' => $this->bulkTripKind($data),
            'category' => $category,
            'vehicle' => $vehicle,
            'options' => $options,
            'plan' => $plan,
            'basePaise' => $base,
            'driverAllowPaise' => $allow,
            'tollPaise' => $toll,
            'gstPaise' => $gst,
            'gstPercent' => $gstPct,
            'totalPaise' => $total,
            'totalRupees' => $total / 100,
            'currency' => 'INR',
        ];
    }

    public function corporateBook(User $actor, array $data): array
    {
        CorporatePlanSchema::ensure();
        $quote = $this->corporateQuote($data);
        $plan = $quote['plan'] ?? [];
        $method = strtoupper((string) ($data['paymentMethod'] ?? 'CORPORATE'));
        $ref = 'CORP'.random_int(1000000, 9999999);
        $accountId = null;
        if (Schema::hasTable('corporate_accounts') && Schema::hasColumn('corporate_accounts', 'owner_user_id')) {
            $accountId = DB::table('corporate_accounts')->where('owner_user_id', $actor->id)->value('id');
        }
        $payload = [
            'public_ref' => $ref,
            'customer_id' => $actor->id,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $map = [
            'corporate_account_id' => $accountId,
            'plan_id' => $plan['id'] ?? null,
            'plan_key' => $plan['key'] ?? null,
            'category' => $data['category'] ?? 'SEDAN',
            'trip_kind' => $quote['tripKind'] ?? 'ONE_WAY',
            'purpose' => $data['purpose'] ?? null,
            'service_key' => $data['serviceKey'] ?? null,
            'quote_paise' => $quote['totalPaise'] ?? 0,
            'pickup_text' => $data['pickupText'] ?? null,
            'drop_text' => $data['dropText'] ?? null,
            'pickup_lat' => $data['pickupLat'] ?? null,
            'pickup_lng' => $data['pickupLng'] ?? null,
            'drop_lat' => $data['dropLat'] ?? null,
            'drop_lng' => $data['dropLng'] ?? null,
            'travel_date' => $data['travelDate'] ?? $data['eventDate'] ?? null,
            'pickup_time' => $data['pickupTime'] ?? $data['eventTime'] ?? null,
            'passengers' => $data['passengers'] ?? 1,
            'passengers_json' => is_array($data['passengerNames'] ?? null) ? json_encode($data['passengerNames']) : ($data['passengerNames'] ?? null),
            'requirements' => $data['requirements'] ?? null,
            'quote_snapshot' => json_encode($quote),
            'payment_method' => $method,
            'payment_status' => 'paid',
            'send_invoice' => ! empty($data['sendInvoice']),
            'contact_name' => $data['contactName'] ?? $actor->name,
            'contact_phone' => $data['contactPhone'] ?? $actor->phone,
        ];
        foreach ($map as $col => $value) {
            if (Schema::hasColumn('corporate_travel_bookings', $col)) {
                $payload[$col] = $value;
            }
        }
        $id = DB::table('corporate_travel_bookings')->insertGetId($payload);
        $row = DB::table('corporate_travel_bookings')->where('id', $id)->first();
        $rideId = $this->attachCorporateRideBooking($actor, $row, $quote, $data, $accountId);
        if ($rideId && Schema::hasColumn('corporate_travel_bookings', 'ride_booking_id')) {
            DB::table('corporate_travel_bookings')->where('id', $id)->update(['ride_booking_id' => $rideId, 'updated_at' => now()]);
            $row = DB::table('corporate_travel_bookings')->where('id', $id)->first();
        }

        return $this->presentCorporateBooking($row, $quote, $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function corporatePlans(): array
    {
        if (! Schema::hasTable('corporate_plans')) {
            return [];
        }
        $q = DB::table('corporate_plans')->orderBy('sort_order')->orderBy('id');
        if (Schema::hasColumn('corporate_plans', 'status')) {
            $q->whereIn('status', ['PUBLISHED', 'published', 'ACTIVE', 'active']);
        }

        return $q->get()->map(fn ($row) => $this->presentCorporatePlan($row))->all();
    }

    private function presentCorporatePlan(object $row): array
    {
        $highlights = $this->decodeList($row->highlights ?? null);
        if (! $highlights && is_string($row->highlights ?? null) && trim((string) $row->highlights) !== '') {
            $highlights = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', (string) $row->highlights) ?: [])));
        }

        return [
            'id' => (string) $row->id,
            'key' => $row->plan_key ?? ('PLAN'.$row->id),
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'pricingMode' => $row->pricing_mode ?? 'VEHICLE',
            'pricePaise' => (int) ($row->price_paise ?? 0),
            'priceRupees' => ((int) ($row->price_paise ?? 0)) / 100,
            'priceLabel' => $row->price_label,
            'gstPercent' => (float) ($row->gst_percent ?? 5),
            'highlights' => $highlights,
            'sortOrder' => (int) ($row->sort_order ?? 0),
            'status' => $row->status ?? 'PUBLISHED',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveCorporatePlan(array $data): array
    {
        $plans = $this->corporatePlans();
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

        return $plans[0] ?? [
            'id' => null,
            'key' => 'ON_DEMAND',
            'title' => 'On-Demand Booking',
            'pricingMode' => 'VEHICLE',
            'pricePaise' => 0,
            'gstPercent' => 5,
            'highlights' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    private function presentCorporateBooking(object $row, array $quote, User $actor): array
    {
        $total = (int) ($row->quote_paise ?? $quote['totalPaise'] ?? 0);

        return [
            'id' => (string) $row->id,
            'ok' => true,
            'publicRef' => $row->public_ref,
            'status' => $row->status,
            'statusLabel' => ucfirst((string) $row->status),
            'plan' => $quote['plan'] ?? null,
            'category' => $row->category ?? null,
            'tripKind' => $row->trip_kind ?? 'ONE_WAY',
            'purpose' => $row->purpose ?? null,
            'pickupText' => $row->pickup_text ?? null,
            'dropText' => $row->drop_text ?? null,
            'travelDate' => $row->travel_date ?? null,
            'pickupTime' => $row->pickup_time ?? null,
            'passengers' => $row->passengers ?? null,
            'passengerNames' => $this->decodeList($row->passengers_json ?? null),
            'requirements' => $row->requirements ?? null,
            'paymentMethod' => $row->payment_method ?? null,
            'contactName' => $row->contact_name ?? $actor->name,
            'contactPhone' => $row->contact_phone ?? $actor->phone,
            'rideBookingId' => $row->ride_booking_id ?? null,
            'quote' => $quote,
            'fare' => [
                'basePaise' => (int) ($quote['basePaise'] ?? 0),
                'tollPaise' => (int) ($quote['tollPaise'] ?? 0),
                'gstPaise' => (int) ($quote['gstPaise'] ?? 0),
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'currency' => 'INR',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $data
     */
    private function attachCorporateRideBooking(User $actor, object $corp, array $quote, array $data, mixed $accountId): ?int
    {
        if (! Schema::hasTable('bookings')) {
            return null;
        }
        if (! empty($corp->ride_booking_id)) {
            return (int) $corp->ride_booking_id;
        }
        $date = $corp->travel_date ?? $data['travelDate'] ?? null;
        $time = (string) ($corp->pickup_time ?? $data['pickupTime'] ?? '10:00');
        $parts = preg_split('/[:.]/', $time) ?: [];
        $start = $date ? \Carbon\Carbon::parse((string) $date)->setTime((int) ($parts[0] ?? 10), (int) ($parts[1] ?? 0)) : now()->addDay();
        $planTitle = is_array($quote['plan'] ?? null) ? (string) ($quote['plan']['title'] ?? '') : '';
        $payload = array_filter([
            'public_ref' => $corp->public_ref,
            'customer_id' => $actor->id,
            'corporate_account_id' => $accountId,
            'product' => 'CORPORATE',
            'category' => strtoupper((string) ($corp->category ?? 'SEDAN')),
            'status' => 'CONFIRMED',
            'pickup_text' => $corp->pickup_text ?? ($data['pickupText'] ?? 'Pickup'),
            'drop_text' => $corp->drop_text ?? ($data['dropText'] ?? 'Drop'),
            'pickup_lat' => $corp->pickup_lat ?? ($data['pickupLat'] ?? 25.8748),
            'pickup_lng' => $corp->pickup_lng ?? ($data['pickupLng'] ?? 86.5961),
            'drop_lat' => $corp->drop_lat ?? ($data['dropLat'] ?? 25.5941),
            'drop_lng' => $corp->drop_lng ?? ($data['dropLng'] ?? 85.1376),
            'quote_paise' => (int) ($corp->quote_paise ?? $quote['totalPaise'] ?? 0),
            'scheduled_at' => $start,
            'passenger_name' => $corp->contact_name ?? $actor->name,
            'passenger_phone' => $corp->contact_phone ?? $actor->phone,
            'instructions' => trim(($data['requirements'] ?? '')."\nCorporate ".$planTitle),
            'start_otp' => (string) random_int(1000, 9999),
            'end_otp' => (string) random_int(1000, 9999),
            'payment_mode' => $corp->payment_method ?? 'CORPORATE',
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('bookings', $key), ARRAY_FILTER_USE_KEY);

        return (int) DB::table('bookings')->insertGetId($payload);
    }

    public function walletMe(User $actor): array
    {
        $ownerType = $actor->role === 'DRIVER' ? 'DRIVER' : 'CUSTOMER';
        $row = null;
        if (Schema::hasTable('wallets')) {
            $q = DB::table('wallets')->where('owner_user_id', $actor->id);
            if (Schema::hasColumn('wallets', 'owner_type')) {
                $q->where(function ($inner) use ($ownerType) {
                    $inner->where('owner_type', $ownerType)->orWhereNull('owner_type');
                });
            }
            $row = $q->orderByDesc('id')->first();
        }
        if (! $row && Schema::hasTable('wallets')) {
            $id = DB::table('wallets')->insertGetId(array_filter([
                'owner_user_id' => $actor->id,
                'owner_type' => $ownerType,
                'balance_paise' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], fn ($key) => Schema::hasColumn('wallets', $key), ARRAY_FILTER_USE_KEY));
            $row = DB::table('wallets')->where('id', $id)->first();
        }
        $balance = (int) ($row->balance_paise ?? 0);
        $ledger = [];
        if ($row && Schema::hasTable('wallet_ledger')) {
            $ledger = DB::table('wallet_ledger')->where('wallet_id', $row->id)->orderByDesc('id')->limit(80)->get()->map(fn ($item) => [
                'id' => (string) $item->id,
                'transactionId' => $item->public_ref ?? ('WL'.$item->id),
                'kind' => $item->kind ?? 'entry',
                'type' => $item->kind ?? 'entry',
                'direction' => $item->direction ?? '',
                'amountPaise' => (int) ($item->amount_paise ?? 0),
                'amountRupees' => ((int) ($item->amount_paise ?? 0)) / 100,
                'commissionPaise' => (int) ($item->commission_paise ?? 0),
                'commissionRupees' => ((int) ($item->commission_paise ?? 0)) / 100,
                'balanceBeforeRupees' => ((int) ($item->balance_before_paise ?? 0)) / 100,
                'balanceAfterRupees' => ((int) ($item->balance_after_paise ?? 0)) / 100,
                'bookingId' => $item->booking_id ?? null,
                'status' => $item->status ?? 'posted',
                'note' => $item->note ?? '',
                'description' => $item->note ?? ($item->kind ?? 'Entry'),
                'createdAt' => $item->created_at ?? null,
            ])->all();
        }

        return [
            'balancePaise' => $balance,
            'balanceRupees' => $balance / 100,
            'ownerType' => $row->owner_type ?? $ownerType,
            'ledger' => $ledger,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bulkVehicleMeta(): array
    {
        return [
            ['key' => 'SEDAN', 'label' => 'Sedan', 'seats' => 4, 'blurb' => 'AC, Comfortable', 'tag' => 'Best for small groups'],
            ['key' => 'SUV', 'label' => 'SUV', 'seats' => 6, 'blurb' => 'AC, Spacious', 'tag' => 'Best for family & team'],
            ['key' => 'TRAVELLER', 'label' => 'Traveller (12 Seater)', 'seats' => 12, 'blurb' => 'AC, Push Back Seats', 'tag' => 'Best for large groups'],
            ['key' => 'TEMPO', 'label' => 'Tempo Traveller (17 Seater)', 'seats' => 17, 'blurb' => 'AC, Comfortable', 'tag' => 'Best for big groups'],
        ];
    }

    private function bulkTripKind(array $data): string
    {
        $raw = strtoupper((string) ($data['tripKind'] ?? $data['product'] ?? 'ONE_WAY'));

        return match ($raw) {
            'ROUND', 'ROUND_TRIP', 'ROUND_WAY' => 'ROUND_WAY',
            'MULTI', 'MULTI_STOP', 'MULTISTOP' => 'MULTI_STOP',
            default => 'ONE_WAY',
        };
    }

    /**
     * @return list<array{category: string, count: int}>
     */
    private function bulkLinesFrom(array $data): array
    {
        $raw = $data['lines'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $lines = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $cat = strtoupper((string) ($row['category'] ?? $row['key'] ?? ''));
                $count = (int) ($row['count'] ?? $row['qty'] ?? 0);
                if ($cat !== '' && $count > 0) {
                    $lines[] = ['category' => $cat, 'count' => $count];
                }
            }
        }
        if ($lines) {
            return $lines;
        }
        $count = max(1, (int) ($data['vehicleCount'] ?? $data['vehicles'] ?? 1));

        return [['category' => strtoupper((string) ($data['category'] ?? 'SEDAN')), 'count' => $count]];
    }

    private function bulkFareCategory(string $category): string
    {
        $key = strtoupper($category);

        return match ($key) {
            'TEMPO', 'TEMPO_TRAVELLER', 'VAN' => 'TRAVELLER',
            'CAR', 'HATCHBACK' => 'SEDAN',
            default => $key,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function bulkUnitQuote(string $category, array $data): array
    {
        $display = strtoupper($category);
        $fareCat = $this->bulkFareCategory($display);
        $meta = collect($this->bulkVehicleMeta())->firstWhere('key', $display)
            ?? collect($this->bulkVehicleMeta())->firstWhere('key', $fareCat)
            ?? ['key' => $display, 'label' => $display, 'seats' => 4, 'blurb' => '', 'tag' => ''];
        $rule = $this->findBulkRateRule($display, $data) ?? $this->findBulkRateRule($fareCat, $data);
        if ($rule) {
            $per = $this->bulkRuleUnitPaise($rule);
            $allow = (int) ($rule->driver_allow_paise ?? 0);
            $toll = (int) ($rule->toll_parking_paise ?? $rule->toll_paise ?? 0);
            $gstPct = (float) ($rule->gst_percent ?? 5);
            $gst = (int) round(($per + $allow) * ($gstPct / 100));

            return array_merge($meta, [
                'category' => $display,
                'perCabPaise' => $per,
                'perCabRupees' => $per / 100,
                'driverAllowPaise' => $allow,
                'tollPaise' => $toll,
                'gstPaise' => $gst,
                'gstPercent' => $gstPct,
                'source' => 'bulk_rate_rules',
            ]);
        }
        $product = $this->bulkTripKind($data);
        $fareInput = [
            'product' => $product,
            'category' => $fareCat,
            'roundTrip' => $product === 'ROUND_WAY',
        ];
        $pLat = isset($data['pickupLat']) ? (float) $data['pickupLat'] : null;
        $pLng = isset($data['pickupLng']) ? (float) $data['pickupLng'] : null;
        $dLat = isset($data['dropLat']) ? (float) $data['dropLat'] : null;
        $dLng = isset($data['dropLng']) ? (float) $data['dropLng'] : null;
        if ($pLat && $pLng && $dLat && $dLng) {
            $fareInput['distanceKm'] = max(1, Geo::haversineKm($pLat, $pLng, $dLat, $dLng));
        }
        try {
            $quote = $this->fares->quote($fareInput);
        } catch (\Throwable) {
            return array_merge($meta, [
                'category' => $display,
                'perCabPaise' => 0,
                'perCabRupees' => 0,
                'driverAllowPaise' => 0,
                'tollPaise' => 0,
                'gstPaise' => 0,
                'source' => 'none',
            ]);
        }
        $bd = is_array($quote['breakdown'] ?? null) ? $quote['breakdown'] : [];
        $total = (int) ($quote['totalPaise'] ?? 0);
        $gst = (int) ($bd['gstPaise'] ?? 0);
        $allow = (int) ($bd['driverAllowPaise'] ?? 0);
        $toll = (int) (($bd['tollPaise'] ?? 0) + ($bd['parkingPaise'] ?? 0));
        $per = max(0, $total - $gst - $allow - $toll);

        return array_merge($meta, [
            'category' => $display,
            'perCabPaise' => $per > 0 ? $per : $total,
            'perCabRupees' => ($per > 0 ? $per : $total) / 100,
            'driverAllowPaise' => $allow,
            'tollPaise' => $toll,
            'gstPaise' => $gst,
            'billedKm' => $quote['billedKm'] ?? null,
            'source' => 'fare_rules',
        ]);
    }

    private function findBulkRateRule(string $category, array $data): ?object
    {
        if (! Schema::hasTable('bulk_rate_rules')) {
            return null;
        }
        $q = DB::table('bulk_rate_rules')->where('category', strtoupper($category));
        if (Schema::hasColumn('bulk_rate_rules', 'active')) {
            $q->where(function ($inner) {
                $inner->where('active', 1)->orWhere('active', true);
            });
        }
        $event = strtoupper((string) ($data['eventKey'] ?? ''));
        if ($event !== '' && Schema::hasColumn('bulk_rate_rules', 'event_key')) {
            $q->where(function ($inner) use ($event) {
                $inner->where('event_key', $event)->orWhereNull('event_key')->orWhere('event_key', '');
            });
        }
        $kind = $this->bulkTripKind($data);
        if (Schema::hasColumn('bulk_rate_rules', 'trip_kind')) {
            $q->where(function ($inner) use ($kind) {
                $inner->where('trip_kind', $kind)->orWhereNull('trip_kind')->orWhere('trip_kind', '');
            });
        }
        $rows = $q->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $scored = $rows->sortByDesc(function ($row) use ($event, $kind) {
            $score = 0;
            if ($event !== '' && strtoupper((string) ($row->event_key ?? '')) === $event) {
                $score += 2;
            }
            if (strtoupper((string) ($row->trip_kind ?? '')) === $kind) {
                $score += 1;
            }

            return $score;
        });

        return $scored->first();
    }

    private function bulkRuleUnitPaise(object $rule): int
    {
        foreach (['per_vehicle_paise', 'price_paise', 'rate_paise', 'fare_paise', 'unit_paise'] as $col) {
            if (isset($rule->{$col}) && (int) $rule->{$col} > 0) {
                return (int) $rule->{$col};
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $data
     */
    private function presentBulkBooking(object $row, array $quote, User $actor): array
    {
        $lines = $quote['lines'] ?? $this->decodeList($row->lines_json ?? null);
        $total = (int) ($row->quote_paise ?? $quote['totalPaise'] ?? 0);

        return [
            'id' => (string) $row->id,
            'ok' => true,
            'publicRef' => $row->public_ref,
            'status' => $row->status,
            'statusLabel' => ucfirst((string) $row->status),
            'eventKey' => $row->event_key ?? null,
            'tripKind' => $row->trip_kind ?? ($quote['tripKind'] ?? 'ONE_WAY'),
            'category' => $row->category ?? null,
            'vehicleCount' => (int) ($row->vehicle_count ?? $quote['vehicleCount'] ?? 0),
            'passengers' => $row->passengers ?? null,
            'pickupText' => $row->pickup_text ?? null,
            'dropText' => $row->drop_text ?? null,
            'eventDate' => $row->event_date ?? null,
            'eventTime' => $row->event_time ?? null,
            'requirements' => $row->requirements ?? null,
            'paymentMethod' => $row->payment_method ?? null,
            'paymentStatus' => $row->payment_status ?? null,
            'rideBookingId' => $row->ride_booking_id ?? null,
            'contactName' => $row->contact_name ?? $actor->name,
            'contactPhone' => $row->contact_phone ?? $actor->phone,
            'lines' => $lines,
            'quote' => $quote,
            'fare' => [
                'basePaise' => (int) ($quote['basePaise'] ?? 0),
                'driverAllowPaise' => (int) ($quote['driverAllowPaise'] ?? 0),
                'tollPaise' => (int) ($quote['tollPaise'] ?? 0),
                'gstPaise' => (int) ($quote['gstPaise'] ?? 0),
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'currency' => 'INR',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $data
     */
    private function attachBulkRideBooking(User $actor, object $bulk, array $quote, array $data): ?int
    {
        if (! Schema::hasTable('bookings')) {
            return null;
        }
        $existing = $bulk->ride_booking_id ?? null;
        if ($existing) {
            return (int) $existing;
        }
        $date = $bulk->event_date ?? $data['eventDate'] ?? null;
        $time = (string) ($bulk->event_time ?? $data['eventTime'] ?? '08:00');
        $parts = preg_split('/[:.]/', $time) ?: [];
        $hour = (int) ($parts[0] ?? 8);
        $minute = (int) ($parts[1] ?? 0);
        $start = $date ? \Carbon\Carbon::parse((string) $date)->setTime($hour, $minute) : now()->addDay();
        $lines = $quote['lines'] ?? [];
        $summary = collect($lines)->map(fn ($line) => ($line['count'] ?? 1).'x '.($line['category'] ?? ''))->implode(', ');
        $payload = array_filter([
            'public_ref' => $bulk->public_ref,
            'customer_id' => $actor->id,
            'product' => 'BULK',
            'category' => strtoupper((string) ($bulk->category ?? $data['category'] ?? 'SEDAN')),
            'status' => 'CONFIRMED',
            'pickup_text' => $bulk->pickup_text ?? ($data['pickupText'] ?? 'Pickup'),
            'drop_text' => $bulk->drop_text ?? ($data['dropText'] ?? 'Drop'),
            'pickup_lat' => $bulk->pickup_lat ?? ($data['pickupLat'] ?? 25.8748),
            'pickup_lng' => $bulk->pickup_lng ?? ($data['pickupLng'] ?? 86.5961),
            'drop_lat' => $bulk->drop_lat ?? ($data['dropLat'] ?? 25.5941),
            'drop_lng' => $bulk->drop_lng ?? ($data['dropLng'] ?? 85.1376),
            'quote_paise' => (int) ($bulk->quote_paise ?? $quote['totalPaise'] ?? 0),
            'scheduled_at' => $start,
            'passenger_name' => $bulk->contact_name ?? $actor->name,
            'passenger_phone' => $bulk->contact_phone ?? $actor->phone,
            'instructions' => trim(($data['requirements'] ?? '')."\nBulk fleet: ".$summary),
            'start_otp' => (string) random_int(1000, 9999),
            'end_otp' => (string) random_int(1000, 9999),
            'payment_mode' => $bulk->payment_method ?? 'CASH',
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('bookings', $key), ARRAY_FILTER_USE_KEY);

        return (int) DB::table('bookings')->insertGetId($payload);
    }

    /**
     * @return list<array{id: string, question: string, answer: string, audience: string}>
     */
    private function defaultFaqs(): array
    {
        return [
            ['id' => '1', 'question' => 'How do I book a ride?', 'answer' => 'Tap Where to, set drop, choose a vehicle, pick Cash or Wallet, then confirm.', 'audience' => 'customer'],
            ['id' => '2', 'question' => 'How do I add wallet balance?', 'answer' => 'Open Account → Wallet, enter an amount, and pay with PayU.', 'audience' => 'customer'],
            ['id' => '3', 'question' => 'How do I save Home and Work?', 'answer' => 'Open Where to and tap Add Home or Add Work, then pick the address.', 'audience' => 'customer'],
            ['id' => '4', 'question' => 'How do I cancel a trip?', 'answer' => 'Open the live booking, tap Cancel, and choose a reason. Admin can see that reason.', 'audience' => 'customer'],
            ['id' => '5', 'question' => 'Safety desk', 'answer' => 'Use Support → Safety desk for SOS, emergency contact, lost & found, and complaints.', 'audience' => 'customer'],
        ];
    }

    private function ensureFaqs(): void
    {
        if (! Schema::hasTable('support_faqs')) {
            try {
                Schema::create('support_faqs', function ($table) {
                    $table->id();
                    $table->string('question');
                    $table->text('answer');
                    $table->string('audience', 24)->default('customer');
                    $table->unsignedInteger('sort_order')->default(0);
                    $table->boolean('active')->default(true);
                    $table->timestamps();
                });
            } catch (\Throwable) {
                return;
            }
        }
        if (Schema::hasTable('support_faqs') && DB::table('support_faqs')->count() === 0) {
            $i = 0;
            foreach ($this->defaultFaqs() as $faq) {
                DB::table('support_faqs')->insert([
                    'question' => $faq['question'],
                    'answer' => $faq['answer'],
                    'audience' => $faq['audience'],
                    'sort_order' => $i++,
                    'active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
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
        $status = strtolower((string) $row->status);
        $pickupUrl = ($row->pickup_lat && $row->pickup_lng)
            ? 'https://www.google.com/maps/dir/?api=1&destination='.$row->pickup_lat.','.$row->pickup_lng.'&travelmode=driving'
            : null;
        $dropUrl = ($row->drop_lat && $row->drop_lng)
            ? 'https://www.google.com/maps/dir/?api=1&destination='.$row->drop_lat.','.$row->drop_lng.'&travelmode=driving'
            : null;
        $actions = match ($status) {
            'assigned' => ['pickup', 'cancel'],
            'picked_up' => ['transit'],
            'in_transit' => ['arrive', 'out_for_delivery'],
            'destination', 'out_for_delivery' => ['deliver'],
            'created', 'paid' => ['accept', 'reject'],
            default => [],
        };

        return [
            'id' => (string) $row->id,
            'kind' => 'parcel',
            'publicRef' => $row->public_ref,
            'customerId' => (string) $row->customer_id,
            'status' => $row->status,
            'statusLabel' => str_replace('_', ' ', $status),
            'lifecycle' => $status,
            'lane' => ! empty($row->bihar_lane) ? 'BIHAR' : 'LOCAL',
            'biharLane' => (bool) $row->bihar_lane,
            'parcelType' => $row->parcel_type,
            'category' => $row->category,
            'pickupText' => $row->pickup_text,
            'dropText' => $row->drop_text,
            'pickup' => $row->pickup_text,
            'destination' => $row->drop_text,
            'pickupLat' => $row->pickup_lat !== null ? (float) $row->pickup_lat : null,
            'pickupLng' => $row->pickup_lng !== null ? (float) $row->pickup_lng : null,
            'dropLat' => $row->drop_lat !== null ? (float) $row->drop_lat : null,
            'dropLng' => $row->drop_lng !== null ? (float) $row->drop_lng : null,
            'quotePaise' => $total,
            'quoteRupees' => $total / 100,
            'paymentStatus' => $row->payment_status,
            'pickupOtp' => $row->pickup_otp,
            'deliveryPin' => $row->delivery_pin,
            'driverId' => $row->driver_id ? (string) $row->driver_id : null,
            'fare' => ['totalPaise' => $total, 'totalRupees' => $total / 100, 'currency' => 'INR'],
            'billedKm' => (float) ($row->distance_km ?? 0),
            'allowedActions' => $actions,
            'navigation' => [
                'pickupUrl' => $pickupUrl,
                'dropUrl' => $dropUrl,
                'currentUrl' => in_array($status, ['in_transit', 'destination', 'out_for_delivery'], true) ? $dropUrl : $pickupUrl,
            ],
            'tracking' => ['Created', 'Assigned', 'Picked Up', 'In Transit', 'Destination', 'Out for Delivery', 'Delivered'],
        ];
    }

    private function presentTravelPackage(object $row): array
    {
        $price = (int) ($row->price_paise ?? 0);
        $dates = $this->decodeList($row->available_dates ?? []);
        $gallery = $this->decodeList($row->gallery ?? []);
        $itinerary = $this->decodeList($row->itinerary ?? []);
        $highlights = $this->decodeList($row->highlights ?? []);
        if ($highlights === [] && is_string($row->highlights ?? null) && trim((string) $row->highlights) !== '') {
            $highlights = preg_split('/\r\n|\n/', (string) $row->highlights) ?: [];
        }
        $vehicle = strtoupper((string) ($row->vehicle_category ?? 'SEDAN'));
        $image = $row->image_url ?? ($gallery[0] ?? null);
        $nights = (int) ($row->nights ?? 0);
        $days = $nights > 0 ? $nights + 1 : (int) round(((int) ($row->duration_hours ?? 24)) / 24);

        return [
            'id' => (int) $row->id,
            'kind' => 'travel',
            'category' => $row->category,
            'categoryLabel' => $row->category,
            'title' => $row->title ?? $row->name,
            'destination' => $row->destination,
            'origin' => $row->origin ?? 'Saharsa, Bihar',
            'region' => $row->region,
            'places' => $row->places,
            'durationHours' => $row->duration_hours,
            'durationLabel' => $row->duration_label ?: ($nights > 0 ? $nights.'N / '.$days.'D' : (($row->duration_hours ?? 0).' hours')),
            'nights' => $nights,
            'kmIncluded' => $row->km_included,
            'vehicleLabel' => $row->vehicle_label ?: match ($vehicle) {
                'TRAVELLER' => 'Traveller',
                'SUV' => 'SUV',
                default => 'Private Cab',
            },
            'vehicleCategory' => $vehicle,
            'driverLabel' => $row->driver_label ?? 'Dedicated driver',
            'minPax' => (int) ($row->min_pax ?? 2),
            'pricePaise' => $price,
            'priceRupees' => $price / 100,
            'inclusions' => $row->inclusions ?? '',
            'exclusions' => $row->exclusions ?? '',
            'highlights' => $highlights,
            'itinerary' => $itinerary,
            'gallery' => $gallery,
            'imageUrl' => $image,
            'popular' => (bool) ($row->popular ?? false),
            'availableDates' => $dates,
            'status' => $row->status ?? 'PUBLISHED',
        ];
    }

    private function presentTravelBooking(object $row, ?object $pkg): array
    {
        $total = (int) ($row->quote_paise ?? 0);
        $guests = (int) ($row->guests ?? 1);
        $unit = $pkg ? (int) ($pkg->price_paise ?? 0) : (int) round($total / max(1, $guests) / 1.05);
        $base = $unit * $guests;
        $gst = max(0, $total - $base);

        return [
            'id' => (string) $row->id,
            'publicRef' => $row->public_ref,
            'status' => $row->status,
            'statusLabel' => ucfirst((string) $row->status),
            'paymentStatus' => $row->payment_status,
            'paymentMethod' => $row->payment_method ?? null,
            'guests' => $guests,
            'travelDate' => $row->travel_date,
            'pickupText' => $row->pickup_text ?? null,
            'contactName' => $row->contact_name ?? null,
            'contactPhone' => $row->contact_phone ?? null,
            'rideBookingId' => $row->ride_booking_id ?? null,
            'package' => $pkg ? $this->presentTravelPackage($pkg) : null,
            'fare' => [
                'basePaise' => $base,
                'gstPaise' => $gst,
                'totalPaise' => $total,
                'totalRupees' => $total / 100,
                'currency' => 'INR',
            ],
        ];
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $value): array
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

    private function attachTravelRideBooking(User $actor, object $travel, ?object $pkg): ?int
    {
        if (! Schema::hasTable('bookings')) {
            return null;
        }
        $existing = $travel->ride_booking_id ?? null;
        if ($existing) {
            return (int) $existing;
        }
        $start = $travel->travel_date ? \Carbon\Carbon::parse((string) $travel->travel_date)->setTime(7, 0) : now()->addDay();
        $payload = array_filter([
            'public_ref' => $travel->public_ref,
            'customer_id' => $actor->id,
            'product' => 'TRAVEL',
            'category' => strtoupper((string) ($pkg->vehicle_category ?? 'TRAVELLER')),
            'status' => 'CONFIRMED',
            'pickup_text' => $travel->pickup_text ?: ($pkg->origin ?? 'Pickup'),
            'drop_text' => $pkg->destination ?? 'Tour',
            'pickup_lat' => 25.8748,
            'pickup_lng' => 86.5961,
            'drop_lat' => 25.8748,
            'drop_lng' => 86.5961,
            'quote_paise' => (int) ($travel->quote_paise ?? 0),
            'scheduled_at' => $start,
            'passenger_name' => $travel->contact_name ?? $actor->name,
            'passenger_phone' => $travel->contact_phone ?? $actor->phone,
            'instructions' => $travel->notes ?? 'Travel & tour package',
            'start_otp' => (string) random_int(1000, 9999),
            'end_otp' => (string) random_int(1000, 9999),
            'payment_mode' => $travel->payment_method ?? 'CASH',
            'created_at' => now(),
            'updated_at' => now(),
        ], fn ($key) => Schema::hasColumn('bookings', $key), ARRAY_FILTER_USE_KEY);

        return (int) DB::table('bookings')->insertGetId($payload);
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
}
