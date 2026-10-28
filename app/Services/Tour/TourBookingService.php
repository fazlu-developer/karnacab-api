<?php

namespace App\Services\Tour;

use App\Models\User;
use App\Services\FcmPushService;
use App\Services\PayUService;
use App\Services\WalletAccountService;
use App\Support\TourBookingSchema;
use App\Support\AdvancePayment;
use App\Support\TourMoney;
use App\Support\TravelPackageSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Travel & Tour state machine. Rows live on travel_bookings, not cab bookings.
 *
 * REQUESTED → APPROVED_UNPAID → CONFIRMED → ASSIGNED → ON_TOUR → COMPLETED
 * Side exits: REJECTED, CANCELLED (customer only before the 30% advance).
 *
 * Money example T = ₹10,000: advance ₹3,000, platform ₹1,000, driver ₹2,000 after assign,
 * ₹7,000 collected by the driver at the end. No second 10% on complete.
 */
class TourBookingService
{
    public function create(User $actor, array $data): array
    {
        $this->boot();
        $pkg = $this->publishedPackage((int) ($data['packageId'] ?? $data['package_id'] ?? 0));
        $passengers = $this->passengers($data);
        $pickup = trim((string) ($data['pickupText'] ?? $data['pickup_text'] ?? $data['pickup'] ?? ''));
        abort_if($pickup === '', 422, 'Pickup location is required');
        $lead = $passengers[0];
        $guests = count($passengers);
        $total = (int) ($pkg->price_paise ?? 0) * $guests;
        abort_if($total < 1, 422, 'This package has no price');
        $start = $data['travelDate'] ?? $data['travel_date'] ?? null;
        $travelDate = $start ? (string) $start : now()->addDay()->toDateString();
        $nights = (int) ($pkg->nights ?? 0);
        $return = $nights > 0 ? \Carbon\Carbon::parse($travelDate)->addDays($nights)->toDateString() : $travelDate;
        $id = DB::table('travel_bookings')->insertGetId($this->only('travel_bookings', [
            'public_ref' => 'KTR'.strtoupper(Str::random(8)),
            'customer_id' => $actor->id,
            'package_id' => $pkg->id,
            'travel_date' => $travelDate,
            'return_date' => $return,
            'guests' => $guests,
            'contact_name' => $lead['name'],
            'contact_phone' => $lead['phone'],
            'pickup_text' => $pickup,
            'pickup_lat' => $data['pickupLat'] ?? $data['pickup_lat'] ?? null,
            'pickup_lng' => $data['pickupLng'] ?? $data['pickup_lng'] ?? null,
            'passengers' => json_encode($passengers),
            'notes' => $data['notes'] ?? null,
            'quote_paise' => $total,
            'payment_status' => 'unpaid',
            'payment_method' => null,
            'status' => 'REQUESTED',
            'advance_paid_paise' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $row = $this->row($id);
        $this->notifyOps('Tour requested', 'Tour '.$row->public_ref.' is waiting for confirmation.', 'tour_requested');

        return $this->present($row, $pkg, $actor);
    }

    public function payAdvance(User $actor, string $id, array $data): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        $method = strtoupper((string) ($data['method'] ?? $data['paymentMethod'] ?? 'ONLINE'));
        abort_if(str_contains($method, 'CASH'), 422, 'Cash is not accepted for the tour advance. Pay online or with wallet.');
        abort_unless($row->status === 'APPROVED_UNPAID', 422, $this->payBlockReason((string) $row->status));
        $split = $this->money((int) $row->quote_paise);
        if (str_contains($method, 'WALLET')) {
            $fresh = $this->settleAdvance($row, 'WALLET', 'TOUR'.$row->id.'-CUSTOMER', $actor);

            return $this->present($fresh, null, $actor);
        }
        $payu = app(PayUService::class)->startTourAdvance($actor, $split['advancePaise'] / 100, (int) $row->id);

        return [
            'booking' => $this->present($row, null, $actor),
            'payment' => $payu,
            'needsTourAdvance' => true,
        ];
    }

    public function settlePayu(int $userId, string $tourId, string $txnid, int $amountPaise): void
    {
        $this->boot();
        if ($tourId === '' || $txnid === '') {
            return;
        }
        $row = DB::table('travel_bookings')->where('id', $this->numericId($tourId))->first();
        if (! $row || (int) $row->customer_id !== $userId) {
            return;
        }
        if ((string) $row->status !== 'APPROVED_UNPAID') {
            return;
        }
        $split = $this->money((int) $row->quote_paise);
        abort_unless($amountPaise === $split['advancePaise'], 422, 'Tour advance amount does not match 30% of the package.');
        $actor = User::query()->find($userId);
        abort_unless($actor, 404);
        $this->settleAdvance($row, 'ONLINE', $txnid, $actor);
    }

    public function cancel(User $actor, string $id): array
    {
        $this->boot();
        $row = $this->owned($actor, $id);
        abort_unless(in_array($row->status, ['REQUESTED', 'APPROVED_UNPAID'], true), 422, 'After the 30% advance, cancellation is an admin decision.');
        DB::table('travel_bookings')->where('id', $row->id)->update($this->only('travel_bookings', [
            'status' => 'CANCELLED',
            'updated_at' => now(),
        ]));

        return $this->present($this->row((int) $row->id), null, $actor);
    }

    public function approve(int $id): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'Only a requested tour can be approved.');
        $ticket = 'TKT'.strtoupper(Str::random(8));
        $invoice = 'TINV'.strtoupper(Str::random(8));
        DB::table('travel_bookings')->where('id', $id)->update($this->only('travel_bookings', [
            'status' => 'APPROVED_UNPAID',
            'ticket_no' => $ticket,
            'invoice_no' => $invoice,
            'approved_at' => now(),
            'updated_at' => now(),
        ]));
        try {
            $this->writeInvoice($this->row($id), $invoice);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('tour.approve_invoice_failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
        $fresh = $this->row($id);
        if ($fresh->customer_id) {
            $this->push([(int) $fresh->customer_id], 'Tour approved', 'Pay the 30% advance to confirm your seat on '.$fresh->public_ref.'.', 'tour_approved');
        }

        return $this->present($fresh);
    }

    public function reject(int $id, string $reason): array
    {
        $this->boot();
        $row = $this->row($id);
        abort_unless($row->status === 'REQUESTED', 422, 'This tour can no longer be rejected.');
        $reason = trim($reason);
        abort_if($reason === '', 422, 'A reject reason is required.');
        DB::table('travel_bookings')->where('id', $id)->update($this->only('travel_bookings', [
            'status' => 'REJECTED',
            'reject_reason' => $reason,
            'updated_at' => now(),
        ]));
        $fresh = $this->row($id);
        if ($fresh->customer_id) {
            $this->push([(int) $fresh->customer_id], 'Tour not confirmed', $reason, 'tour_rejected');
        }

        return $this->present($fresh);
    }

    public function assign(int $id, int $driverId): array
    {
        $this->boot();
        return DB::transaction(function () use ($id, $driverId) {
            $row = DB::table('travel_bookings')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'CONFIRMED', 422, 'Assign a driver only after the 30% advance is paid.');
            $driver = Schema::hasTable('drivers') ? DB::table('drivers')->where('id', $driverId)->first() : null;
            abort_unless($driver, 422, 'Choose a driver from the list.');
            $userId = (int) ($driver->user_id ?? 0);
            abort_if($userId < 1, 422, 'That driver has no login.');
            $split = $this->money((int) $row->quote_paise);
            $this->releaseDriverAdvance($userId, (int) $row->id, $split);
            $otp = (string) random_int(1000, 9999);
            DB::table('travel_bookings')->where('id', $id)->update($this->only('travel_bookings', [
                'status' => 'ASSIGNED',
                'driver_id' => $driverId,
                'start_otp' => $otp,
                'driver_advance_paise' => $split['driverAdvancePaise'],
                'assigned_at' => now(),
                'updated_at' => now(),
            ]));
            $fresh = $this->row($id);
            $this->push([(int) $fresh->customer_id, $userId], 'Tour assigned', 'Tour '.$fresh->public_ref.' has a driver. The customer has the start OTP.', 'tour_assigned');

            return $this->present($fresh);
        });
    }

    public function start(User $actor, string $id, string $otp): array
    {
        $this->boot();
        $row = $this->forDriver($actor, $id);
        abort_unless($row->status === 'ASSIGNED', 422, 'This tour is not ready to start.');
        abort_unless(hash_equals((string) $row->start_otp, trim($otp)), 422, 'Start OTP does not match.');
        DB::table('travel_bookings')->where('id', $row->id)->update($this->only('travel_bookings', [
            'status' => 'ON_TOUR',
            'started_at' => now(),
            'updated_at' => now(),
        ]));
        $fresh = $this->row((int) $row->id);
        if ($fresh->customer_id) {
            $this->push([(int) $fresh->customer_id], 'Tour started', 'Your tour '.$fresh->public_ref.' has started.', 'tour_started');
        }

        return $this->present($fresh, null, $actor);
    }

    public function complete(User $actor, string $id, array $data): array
    {
        $this->boot();
        $row = $this->forDriver($actor, $id);
        abort_unless($row->status === 'ON_TOUR', 422, 'Start the tour before completing it.');
        $collected = filter_var($data['collected'] ?? $data['paymentCollected'] ?? false, FILTER_VALIDATE_BOOLEAN);
        abort_unless($collected, 422, 'Confirm that the remaining 70% was collected.');
        $split = $this->money((int) $row->quote_paise);
        DB::transaction(function () use ($row, $split, $actor) {
            $locked = DB::table('travel_bookings')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($locked && $locked->status === 'ON_TOUR', 422, 'This tour is already finished.');
            $driverUserId = (int) $actor->id;
            $this->postCollectedOutside($driverUserId, (int) $locked->id, $split['balancePaise'], (int) $locked->quote_paise);
            DB::table('travel_bookings')->where('id', $locked->id)->update($this->only('travel_bookings', [
                'status' => 'COMPLETED',
                'payment_status' => 'collected',
                'collected_outside_paise' => $split['balancePaise'],
                'completed_at' => now(),
                'updated_at' => now(),
            ]));
        });
        $fresh = $this->row((int) $row->id);
        if ($fresh->customer_id) {
            $this->push([(int) $fresh->customer_id], 'Tour completed', 'Tour '.$fresh->public_ref.' is complete.', 'tour_completed');
        }

        return $this->present($fresh, null, $actor);
    }

    public function show(User $actor, string $id): array
    {
        $this->boot();
        if ($actor->role === 'DRIVER') {
            return $this->present($this->forDriver($actor, $id), null, $actor);
        }

        return $this->present($this->owned($actor, $id), null, $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customerCards(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('travel_bookings')) {
            return [];
        }

        return DB::table('travel_bookings')->where('customer_id', $actor->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn ($row) => $this->present($row, null, $actor))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function driverCards(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('travel_bookings') || ! Schema::hasTable('drivers')) {
            return [];
        }
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        if ($driverId < 1) {
            return [];
        }

        return DB::table('travel_bookings')->where('driver_id', $driverId)->orderByDesc('id')->limit(40)->get()
            ->map(fn ($row) => $this->driverTrip($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoiceRows(User $actor): array
    {
        $this->boot();
        if (! Schema::hasTable('travel_bookings') || $actor->role === 'DRIVER') {
            return [];
        }

        return DB::table('travel_bookings')
            ->where('customer_id', $actor->id)
            ->whereNotNull('invoice_no')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn ($row) => $this->invoiceMap($row))
            ->all();
    }

    private function settleAdvance(object $row, string $method, string $paymentRef, User $actor): object
    {
        return DB::transaction(function () use ($row, $method, $paymentRef, $actor) {
            $locked = DB::table('travel_bookings')->where('id', $row->id)->lockForUpdate()->first();
            abort_unless($locked && $locked->status === 'APPROVED_UNPAID', 422, 'This tour is not waiting for the 30% advance.');
            $split = $this->money((int) $locked->quote_paise);
            if ($method === 'WALLET') {
                $this->debitCustomer((int) $actor->id, (int) $locked->id, $split['advancePaise'], $paymentRef);
            }
            $this->creditPlatform((int) $locked->id, $split);
            $this->holdDriverEscrow((int) $locked->id, $split);
            DB::table('travel_bookings')->where('id', $locked->id)->update($this->only('travel_bookings', [
                'status' => 'CONFIRMED',
                'payment_status' => 'advance_paid',
                'payment_method' => $method,
                'advance_paid_paise' => $split['advancePaise'],
                'advance_payment_ref' => $paymentRef,
                'platform_commission_paise' => $split['platformCommissionPaise'],
                'driver_advance_paise' => $split['driverAdvancePaise'],
                'updated_at' => now(),
            ]));
            $fresh = $this->row((int) $locked->id);
            $this->notifyOps('Tour advance paid', 'Assign a driver for '.$fresh->public_ref.'. 30% is in.', 'tour_paid');

            return $fresh;
        });
    }

    private function debitCustomer(int $userId, int $tourId, int $amount, string $ref): void
    {
        if ($this->ledgerExists($ref)) {
            return;
        }
        $wallet = app(WalletAccountService::class)->ensureWallet($userId, 'CUSTOMER');
        abort_unless($wallet, 422, 'Wallet not found');
        $before = (int) $wallet->balance_paise;
        abort_unless($before >= $amount, 422, 'Insufficient wallet balance');
        $after = $before - $amount;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        $this->insertLedger($wallet, $userId, 'CUSTOMER', 'DEBIT', $amount, $before, $after, 'tour_advance', 'Tour 30% advance', $ref);
    }

    /**
     * @param  array{totalPaise: int, advancePaise: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}  $split
     */
    private function creditPlatform(int $tourId, array $split): void
    {
        $userId = $this->platformUserId();
        if ($userId < 1 || $split['platformCommissionPaise'] < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'PLATFORM',
            0,
            $split['platformCommissionPaise'],
            $split['totalPaise'],
            'tour_platform',
            'Tour commission 10% of package total',
            ['PLATFORM', 'ADMIN'],
            'TOUR'.$tourId.'-PLATFORM',
        );
    }

    /**
     * @param  array{totalPaise: int, advancePaise: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}  $split
     */
    private function holdDriverEscrow(int $tourId, array $split): void
    {
        $userId = $this->platformUserId();
        if ($userId < 1 || $split['driverAdvancePaise'] < 1) {
            return;
        }
        app(WalletAccountService::class)->creditAccount(
            $userId,
            'PLATFORM',
            0,
            $split['driverAdvancePaise'],
            $split['totalPaise'],
            'tour_driver_escrow',
            'Tour driver 20% held until a driver is assigned',
            ['PLATFORM', 'ADMIN'],
            'TOUR'.$tourId.'-ESCROW',
        );
    }

    /**
     * @param  array{totalPaise: int, advancePaise: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}  $split
     */
    private function releaseDriverAdvance(int $driverUserId, int $tourId, array $split): void
    {
        $amount = $split['driverAdvancePaise'];
        if ($amount < 1) {
            return;
        }
        $platformId = $this->platformUserId();
        if ($platformId > 0) {
            $this->debitWallet($platformId, 'PLATFORM', $amount, 'tour_driver_escrow_release', 'TOUR'.$tourId.'-ESCROW-OUT', 'Released tour driver advance from escrow');
        }
        app(WalletAccountService::class)->creditAccount(
            $driverUserId,
            'DRIVER',
            0,
            $amount,
            $split['totalPaise'],
            'tour_driver_advance',
            'Tour advance share 20% of package total',
            ['DRIVER'],
            'TOUR'.$tourId.'-DRIVER',
        );
    }

    private function postCollectedOutside(int $driverUserId, int $tourId, int $amount, int $gross): void
    {
        $ref = 'TOUR'.$tourId.'-COLLECTED';
        if ($amount < 1 || $this->ledgerExists($ref)) {
            return;
        }
        $wallet = app(WalletAccountService::class)->ensureWallet($driverUserId, 'DRIVER');
        if (! $wallet) {
            return;
        }
        $balance = (int) $wallet->balance_paise;
        $this->insertLedger($wallet, $driverUserId, 'DRIVER', 'CREDIT', $amount, $balance, $balance, 'collected_outside', 'Tour 70% collected by driver. No extra platform commission.', $ref, $gross);
    }

    private function debitWallet(int $userId, string $ownerType, int $amount, string $kind, string $ref, string $note): void
    {
        if ($this->ledgerExists($ref)) {
            return;
        }
        $wallet = app(WalletAccountService::class)->ensureWallet($userId, $ownerType);
        if (! $wallet) {
            return;
        }
        $before = (int) $wallet->balance_paise;
        $after = $before - $amount;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        $this->insertLedger($wallet, $userId, $ownerType, 'DEBIT', $amount, $before, $after, $kind, $note, $ref);
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

    private function writeInvoice(object $row, string $invoiceNo): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }
        if (DB::table('invoices')->where('public_ref', $invoiceNo)->exists()) {
            return;
        }
        $payload = [
            'public_ref' => $invoiceNo,
            'customer_id' => $row->customer_id,
            'kind' => 'tour',
            'product' => 'TRAVEL',
            'status' => 'issued',
            'subtotal_paise' => (int) $row->quote_paise,
            'tax_paise' => 0,
            'total_paise' => (int) $row->quote_paise,
            'paid_paise' => 0,
            'refunded_paise' => 0,
            'currency' => 'INR',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('invoices', 'booking_id')) {
            $nullable = true;
            try {
                $col = DB::selectOne("SHOW COLUMNS FROM invoices LIKE 'booking_id'");
                $nullable = strtoupper((string) ($col->Null ?? 'YES')) !== 'NO';
            } catch (\Throwable) {
            }
            $payload['booking_id'] = $nullable ? null : 0;
        }
        DB::table('invoices')->insert($this->only('invoices', $payload));
    }

    private function present(object $row, ?object $pkg = null, ?User $viewer = null): array
    {
        $pkg = $pkg ?: (Schema::hasTable('travel_packages') ? DB::table('travel_packages')->where('id', $row->package_id)->first() : null);
        $split = $this->money((int) ($row->quote_paise ?? 0));
        $status = (string) ($row->status ?? 'REQUESTED');
        $passengers = $this->decodePassengers($row->passengers ?? null);
        $ticketReady = ! in_array($status, ['REQUESTED', 'REJECTED', 'CANCELLED'], true);
        $assigned = $status === 'ASSIGNED';
        $showOtp = $assigned && ($viewer === null || $viewer->role !== 'DRIVER');

        return [
            'id' => 'tour-'.$row->id,
            'tourId' => (string) $row->id,
            'kind' => 'tour',
            'product' => 'TRAVEL',
            'publicRef' => $row->public_ref,
            'status' => $status,
            'statusLabel' => $this->label($status),
            'lifecycle' => $this->lifecycle($status),
            'lifecycleLabel' => $this->label($status),
            'tripBucket' => $this->bucket($status),
            'paymentStatus' => $row->payment_status,
            'paymentMethod' => $row->payment_method,
            'guests' => (int) ($row->guests ?? count($passengers)),
            'travellers' => $passengers,
            'passengers' => $passengers,
            'travelDate' => $row->travel_date ?? null,
            'returnDate' => $row->return_date ?? null,
            'scheduledAt' => $row->travel_date ?? null,
            'createdAt' => $row->created_at ?? null,
            'pickupText' => $row->pickup_text ?? null,
            'dropText' => $pkg->destination ?? ($pkg->title ?? 'Tour'),
            'packageTitle' => $pkg->title ?? $pkg->name ?? 'Tour',
            'package' => $pkg ? [
                'id' => (int) $pkg->id,
                'title' => $pkg->title ?? $pkg->name,
                'destination' => $pkg->destination ?? null,
                'durationLabel' => $pkg->duration_label ?? null,
                'itinerary' => $pkg->itinerary ?? null,
                'imageUrl' => $pkg->image_url ?? null,
                'pricePaise' => (int) ($pkg->price_paise ?? 0),
            ] : null,
            'contactName' => $row->contact_name ?? null,
            'contactPhone' => $row->contact_phone ?? null,
            'category' => $pkg->vehicle_category ?? 'TRAVEL',
            'needsTourAdvance' => $status === 'APPROVED_UNPAID',
            'needsAdvance' => false,
            'advanceAfterAccept' => false,
            'tracking' => false,
            'driverLat' => null,
            'driverLng' => null,
            'startOtp' => $showOtp ? ($row->start_otp ?? null) : null,
            'ticketNo' => $ticketReady ? ($row->ticket_no ?? null) : null,
            'invoiceNo' => $ticketReady ? ($row->invoice_no ?? null) : null,
            'ticket' => $ticketReady ? [
                'number' => $row->ticket_no,
                'status' => $status === 'APPROVED_UNPAID' ? 'unpaid' : $status,
            ] : null,
            'invoice' => $ticketReady ? $this->invoiceMap($row, $split) : null,
            'rejectReason' => $row->reject_reason ?? null,
            'canCancel' => in_array($status, ['REQUESTED', 'APPROVED_UNPAID'], true),
            'allowedActions' => $this->actions($status, $ticketReady),
            'fare' => [
                'totalPaise' => $split['totalPaise'],
                'totalRupees' => $split['totalPaise'] / 100,
                'advancePaise' => $split['advancePaise'],
                'advanceRupees' => $split['advancePaise'] / 100,
                'platformCommissionPaise' => $split['platformCommissionPaise'],
                'driverAdvancePaise' => $split['driverAdvancePaise'],
                'balancePaise' => $split['balancePaise'],
                'balanceRupees' => $split['balancePaise'] / 100,
                'advancePercent' => $split['advancePercent'] ?? AdvancePayment::percent('TRAVEL'),
                'currency' => 'INR',
            ],
            'quotePaise' => $split['totalPaise'],
            'quoteRupees' => $split['totalPaise'] / 100,
            'money' => [
                'totalPaise' => $split['totalPaise'],
                'advancePaise' => (int) ($row->advance_paid_paise ?? 0) > 0 ? $split['advancePaise'] : 0,
                'dueAdvancePaise' => $status === 'APPROVED_UNPAID' ? $split['advancePaise'] : 0,
                'platformCommissionPaise' => $split['platformCommissionPaise'],
                'driverAdvancePaise' => $split['driverAdvancePaise'],
                'balancePaise' => $split['balancePaise'],
                'collectedOutsidePaise' => (int) ($row->collected_outside_paise ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function driverTrip(object $row): array
    {
        $card = $this->present($row);
        $card['customerPhone'] = $row->contact_phone ?? null;
        $card['actions'] = match ((string) $row->status) {
            'ASSIGNED' => ['call', 'start'],
            'ON_TOUR' => ['call', 'complete'],
            default => ['call'],
        };
        $card['lifecycle'] = match ((string) $row->status) {
            'ON_TOUR' => 'started',
            'COMPLETED' => 'completed',
            'CANCELLED', 'REJECTED' => 'cancelled',
            default => 'scheduled',
        };
        $card['collectRupees'] = ($card['fare']['balancePaise'] ?? 0) / 100;
        unset($card['startOtp']);

        return $card;
    }

    /**
     * @param  array{totalPaise: int, advancePaise: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}|null  $split
     * @return array<string, mixed>
     */
    private function invoiceMap(object $row, ?array $split = null): array
    {
        $split = $split ?: $this->money((int) ($row->quote_paise ?? 0));
        $paid = (int) ($row->advance_paid_paise ?? 0);

        return [
            'id' => 'tour-'.$row->id,
            'bookingId' => 'tour-'.$row->id,
            'publicRef' => $row->invoice_no ?: ($row->public_ref ?? ''),
            'invoiceNumber' => $row->invoice_no,
            'ticketNo' => $row->ticket_no,
            'kind' => 'tour',
            'product' => 'TRAVEL',
            'status' => $paid > 0 ? 'advance_paid' : 'issued',
            'paymentMode' => $row->payment_method ?: 'UNPAID',
            'customerName' => $row->contact_name,
            'customerPhone' => $row->contact_phone,
            'pickup' => $row->pickup_text,
            'drop' => 'Tour package',
            'totalPaise' => $split['totalPaise'],
            'totalRupees' => $split['totalPaise'] / 100,
            'subtotalRupees' => $split['totalPaise'] / 100,
            'gstRupees' => 0,
            'advanceRupees' => $split['advancePaise'] / 100,
            'balanceRupees' => $split['balancePaise'] / 100,
            'platformRupees' => $split['platformCommissionPaise'] / 100,
            'driverAdvanceRupees' => $split['driverAdvancePaise'] / 100,
            'issuedAt' => $row->approved_at ?? $row->created_at ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function actions(string $status, bool $ticketReady): array
    {
        $actions = ['support'];
        if ($ticketReady) {
            $actions[] = 'ticket';
            $actions[] = 'invoice';
        }
        if ($status === 'APPROVED_UNPAID') {
            $actions[] = 'pay30';
        }
        if ($status === 'ASSIGNED') {
            $actions[] = 'otp';
        }
        if (in_array($status, ['REQUESTED', 'APPROVED_UNPAID'], true)) {
            $actions[] = 'cancel';
        }

        return $actions;
    }

    private function label(string $status): string
    {
        return match ($status) {
            'REQUESTED' => 'Waiting for confirmation',
            'APPROVED_UNPAID' => 'Approved — pay 30%',
            'CONFIRMED' => 'Confirmed',
            'ASSIGNED' => 'Assigned — start OTP',
            'ON_TOUR' => 'Tour started',
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
            'APPROVED_UNPAID' => 'pending',
            'CONFIRMED', 'ASSIGNED' => 'scheduled',
            'ON_TOUR' => 'on_tour',
            'COMPLETED' => 'completed',
            'REJECTED', 'CANCELLED' => 'cancelled',
            default => 'pending',
        };
    }

    private function bucket(string $status): string
    {
        return match ($status) {
            'COMPLETED', 'REJECTED', 'CANCELLED' => 'history',
            default => 'upcoming',
        };
    }

    private function payBlockReason(string $status): string
    {
        return match ($status) {
            'REQUESTED' => 'Waiting for confirmation. The ticket is not ready yet.',
            'CONFIRMED', 'ASSIGNED', 'ON_TOUR', 'COMPLETED' => 'The 30% advance is already paid.',
            'REJECTED' => 'This tour was rejected.',
            'CANCELLED' => 'This tour was cancelled.',
            default => 'This tour cannot be paid right now.',
        };
    }

    /**
     * @return list<array{name: string, phone: string, age: string, gender: string}>
     */
    private function passengers(array $data): array
    {
        $raw = $data['travellers'] ?? $data['passengers'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        abort_unless(is_array($raw) && $raw !== [], 422, 'Add one passenger for every traveller.');
        $rows = [];
        foreach (array_values($raw) as $index => $item) {
            if (! is_array($item)) {
                abort(422, 'Passenger details are incomplete.');
            }
            $name = trim((string) ($item['name'] ?? ''));
            $phone = preg_replace('/\D/', '', (string) ($item['phone'] ?? '')) ?? '';
            $phone = strlen($phone) > 10 ? substr($phone, -10) : $phone;
            abort_if(strlen($name) < 2, 422, 'Each traveller needs a name.');
            if ($index === 0) {
                abort_unless(strlen($phone) === 10, 422, 'The lead traveller needs a 10-digit phone number.');
            }
            $rows[] = [
                'name' => $name,
                'phone' => $phone,
                'age' => trim((string) ($item['age'] ?? '')),
                'gender' => trim((string) ($item['gender'] ?? '')),
            ];
        }
        $expected = (int) ($data['guests'] ?? $data['travellersCount'] ?? count($rows));
        abort_unless($expected === count($rows), 422, 'Passenger count does not match the number of travellers.');

        return $rows;
    }

    private function publishedPackage(int $id): object
    {
        abort_if($id < 1 || ! Schema::hasTable('travel_packages'), 404, 'Choose a published tour package.');
        $pkg = DB::table('travel_packages')->where('id', $id)->first();
        abort_unless($pkg, 404, 'Choose a published tour package.');
        $status = strtoupper((string) ($pkg->status ?? 'PUBLISHED'));
        abort_unless($status === 'PUBLISHED', 422, 'This package is not published.');

        return $pkg;
    }

    private function owned(User $actor, string $id): object
    {
        $row = $this->row($this->numericId($id));
        abort_unless((int) $row->customer_id === (int) $actor->id || in_array($actor->role, ['ADMIN', 'SUPER_ADMIN'], true), 403);

        return $row;
    }

    private function forDriver(User $actor, string $id): object
    {
        abort_unless($actor->role === 'DRIVER', 403);
        $driverId = (int) (DB::table('drivers')->where('user_id', $actor->id)->value('id') ?? 0);
        $row = $this->row($this->numericId($id));
        abort_unless($driverId > 0 && (int) ($row->driver_id ?? 0) === $driverId, 403);

        return $row;
    }

    private function row(int $id): object
    {
        $row = DB::table('travel_bookings')->where('id', $id)->first();
        abort_unless($row, 404, 'Tour booking not found');

        return $row;
    }

    private function numericId(string $id): int
    {
        $id = preg_replace('/^tour-/', '', $id) ?? $id;

        return (int) $id;
    }

    /**
     * @return list<array{name: string, phone: string, age: string, gender: string}>
     */
    private function decodePassengers(mixed $value): array
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

    private function platformUserId(): int
    {
        if (! Schema::hasTable('users')) {
            return 0;
        }

        return (int) (DB::table('users')->whereIn('role', ['ADMIN', 'SUPER_ADMIN'])->orderBy('id')->value('id') ?? 0);
    }

    /**
     * @param  list<int>  $userIds
     */
    private function push(array $userIds, string $title, string $body, string $event): void
    {
        $ids = array_values(array_filter($userIds, fn ($id) => $id > 0));
        if ($ids === []) {
            return;
        }
        try {
            app(FcmPushService::class)->notifyUsers($ids, $title, $body, ['type' => 'tour', 'event' => $event]);
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
     * @return array{totalPaise: int, advancePaise: int, advancePercent: int, platformCommissionPaise: int, driverAdvancePaise: int, balancePaise: int}
     */
    private function money(int $totalPaise): array
    {
        return TourMoney::split($totalPaise, AdvancePayment::percent('TRAVEL'));
    }

    private function boot(): void
    {
        TravelPackageSchema::ensure();
        TourBookingSchema::ensure();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function only(string $table, array $row): array
    {
        if (! Schema::hasTable($table)) {
            return $row;
        }

        return array_filter(
            $row,
            fn ($key) => Schema::hasColumn($table, $key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
