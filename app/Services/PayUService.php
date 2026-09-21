<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PayUService
{
    public function startTopup(User $actor, float $rupees): array
    {
        abort_unless($rupees >= 1, 422, 'Enter at least ₹1');
        abort_unless($rupees <= 50000, 422, 'Maximum top-up is ₹50,000');
        $this->ensureTables();
        $txnid = 'KC'.strtoupper(Str::random(14));
        $amount = number_format($rupees, 2, '.', '');
        $email = $actor->email && ! str_ends_with((string) $actor->email, '@otp.karnacab.local')
            ? (string) $actor->email
            : 'rider'.$actor->id.'@karnacab.in';
        $firstname = $actor->name ?: 'Customer';
        $productinfo = 'KarnaCab wallet top-up';
        $hash = $this->requestHash($txnid, $amount, $productinfo, $firstname, $email);
        $payload = [
            'txnid' => $txnid,
            'user_id' => $actor->id,
            'amount_paise' => (int) round($rupees * 100),
            'status' => 'initiated',
            'gateway' => 'payu',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('payment_intents')->insert($this->filter('payment_intents', $payload));
        $base = rtrim((string) (config('app.url') ?: env('API_PUBLIC_URL')), '/');
        $checkout = $base.'/api/v1/payments/payu/checkout/'.$txnid;

        return [
            'ok' => true,
            'txnid' => $txnid,
            'amountRupees' => (float) $amount,
            'checkoutUrl' => $checkout,
            'gateway' => 'payu',
            'mode' => env('PAYU_MODE', 'test'),
        ];
    }

    public function checkoutPage(string $txnid): string
    {
        $row = DB::table('payment_intents')->where('txnid', $txnid)->first();
        abort_unless($row, 404, 'Payment not found');
        $user = User::query()->find($row->user_id);
        abort_unless($user, 404, 'Customer not found');
        $amount = number_format(((int) $row->amount_paise) / 100, 2, '.', '');
        $email = $user->email && ! str_ends_with((string) $user->email, '@otp.karnacab.local')
            ? (string) $user->email
            : 'rider'.$user->id.'@karnacab.in';
        $firstname = e($user->name ?: 'Customer');
        $productinfo = 'KarnaCab wallet top-up';
        $hash = $this->requestHash($txnid, $amount, $productinfo, $user->name ?: 'Customer', $email);
        $action = $this->payuUrl();
        $surl = url('/api/v1/payments/webhooks/payu');
        $key = e($this->key());
        $phone = e((string) ($user->phone ?: '9999999999'));
        $emailEsc = e($email);
        $txn = e($txnid);

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>PayU checkout</title></head>
<body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;background:#f4f1ea">
  <p>Redirecting to PayU…</p>
  <form id="payu" action="{$action}" method="post">
    <input type="hidden" name="key" value="{$key}">
    <input type="hidden" name="txnid" value="{$txn}">
    <input type="hidden" name="amount" value="{$amount}">
    <input type="hidden" name="productinfo" value="{$productinfo}">
    <input type="hidden" name="firstname" value="{$firstname}">
    <input type="hidden" name="email" value="{$emailEsc}">
    <input type="hidden" name="phone" value="{$phone}">
    <input type="hidden" name="surl" value="{$surl}">
    <input type="hidden" name="furl" value="{$surl}">
    <input type="hidden" name="hash" value="{$hash}">
    <input type="hidden" name="service_provider" value="payu_paisa">
  </form>
  <script>document.getElementById('payu').submit();</script>
</body></html>
HTML;
    }

    public function handleWebhook(Request $request): array
    {
        $data = $request->all();
        $txnid = (string) ($data['txnid'] ?? $data['txnId'] ?? '');
        $status = strtolower((string) ($data['status'] ?? ''));
        abort_unless($txnid !== '', 422, 'txnid required');
        if (! $this->verifyResponse($data)) {
            Log::warning('payu.hash_mismatch', ['txnid' => $txnid]);
            abort(422, 'Invalid PayU hash');
        }
        $intent = DB::table('payment_intents')->where('txnid', $txnid)->first();
        abort_unless($intent, 404, 'Unknown txnid');
        if (in_array($status, ['success', 'captured'], true)) {
            $this->creditWallet((int) $intent->user_id, (int) $intent->amount_paise, $txnid);
            DB::table('payment_intents')->where('id', $intent->id)->update($this->filter('payment_intents', [
                'status' => 'captured',
                'gateway_status' => $status,
                'updated_at' => now(),
            ]));

            return ['ok' => true, 'status' => 'captured', 'txnid' => $txnid];
        }
        DB::table('payment_intents')->where('id', $intent->id)->update($this->filter('payment_intents', [
            'status' => $status ?: 'failed',
            'gateway_status' => $status,
            'updated_at' => now(),
        ]));

        return ['ok' => true, 'status' => $status ?: 'failed', 'txnid' => $txnid];
    }

    private function creditWallet(int $userId, int $paise, string $txnid): void
    {
        if (! Schema::hasTable('wallets')) {
            return;
        }
        $ownerType = (string) (User::query()->where('id', $userId)->value('role') ?: 'CUSTOMER');
        if (! in_array($ownerType, ['DRIVER', 'CUSTOMER'], true)) {
            $ownerType = 'CUSTOMER';
        }
        $walletQuery = DB::table('wallets')->where('owner_user_id', $userId);
        if (Schema::hasColumn('wallets', 'owner_type')) {
            $walletQuery->where('owner_type', $ownerType);
        }
        $wallet = $walletQuery->orderBy('id')->first();
        if (! $wallet) {
            $id = DB::table('wallets')->insertGetId($this->filter('wallets', [
                'owner_user_id' => $userId,
                'owner_type' => $ownerType,
                'balance_paise' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $wallet = DB::table('wallets')->where('id', $id)->first();
        }
        if (Schema::hasTable('wallet_ledger') && Schema::hasColumn('wallet_ledger', 'note')) {
            $exists = DB::table('wallet_ledger')->where('wallet_id', $wallet->id)->where('note', 'payu:'.$txnid)->exists();
            if ($exists) {
                return;
            }
        }
        $before = (int) $wallet->balance_paise;
        $after = $before + $paise;
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_paise' => $after, 'updated_at' => now()]);
        if (Schema::hasTable('wallet_ledger')) {
            DB::table('wallet_ledger')->insert($this->filter('wallet_ledger', [
                'public_ref' => 'WU'.strtoupper(Str::random(10)),
                'wallet_id' => $wallet->id,
                'owner_user_id' => $userId,
                'account' => $ownerType,
                'direction' => 'credit',
                'amount_paise' => $paise,
                'balance_before_paise' => $before,
                'balance_after_paise' => $after,
                'kind' => 'topup',
                'status' => 'posted',
                'note' => 'payu:'.$txnid,
                'created_at' => now(),
            ]));
        }
        try {
            app(FcmPushService::class)->notifyUsers(
                [$userId],
                'Wallet credited',
                '₹'.number_format($paise / 100, 0).' added to your KarnaCab wallet.',
                ['type' => 'wallet', 'event' => 'credited', 'txnid' => $txnid],
            );
        } catch (\Throwable) {
        }
    }

    private function requestHash(string $txnid, string $amount, string $productinfo, string $firstname, string $email): string
    {
        $seq = $this->key().'|'.$txnid.'|'.$amount.'|'.$productinfo.'|'.$firstname.'|'.$email.'|||||||||||'.$this->salt();

        return strtolower(hash('sha512', $seq));
    }

    private function verifyResponse(array $data): bool
    {
        $hash = (string) ($data['hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        $status = (string) ($data['status'] ?? '');
        $email = (string) ($data['email'] ?? '');
        $firstname = (string) ($data['firstname'] ?? '');
        $productinfo = (string) ($data['productinfo'] ?? '');
        $amount = (string) ($data['amount'] ?? '');
        $txnid = (string) ($data['txnid'] ?? '');
        $udf = [];
        for ($i = 5; $i >= 1; $i--) {
            $udf[] = (string) ($data['udf'.$i] ?? '');
        }
        $seq = $this->salt().'|'.$status.'||||||'.implode('|', $udf).'|'.$email.'|'.$firstname.'|'.$productinfo.'|'.$amount.'|'.$txnid.'|'.$this->key();

        return hash_equals(strtolower(hash('sha512', $seq)), strtolower($hash));
    }

    private function payuUrl(): string
    {
        return strtolower((string) env('PAYU_MODE', 'test')) === 'live'
            ? 'https://secure.payu.in/_payment'
            : 'https://test.payu.in/_payment';
    }

    private function key(): string
    {
        return (string) env('PAYU_KEY', '');
    }

    private function salt(): string
    {
        return (string) env('PAYU_SALT', '');
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('payment_intents')) {
            Schema::create('payment_intents', function ($table) {
                $table->id();
                $table->string('txnid', 40)->unique();
                $table->unsignedBigInteger('user_id');
                $table->unsignedInteger('amount_paise')->default(0);
                $table->string('status', 24)->default('initiated');
                $table->string('gateway', 24)->nullable();
                $table->string('gateway_status', 32)->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('wallets')) {
            Schema::create('wallets', function ($table) {
                $table->id();
                $table->unsignedBigInteger('owner_user_id');
                $table->string('owner_type', 24)->default('CUSTOMER');
                $table->integer('balance_paise')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function filter(string $table, array $row): array
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
