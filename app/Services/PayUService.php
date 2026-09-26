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
        abort_unless($this->key() !== '' && $this->salt() !== '', 422, 'PayU is not configured');
        $this->ensureTables();
        $txnid = 'KC'.strtoupper(Str::random(14));
        $amount = number_format($rupees, 2, '.', '');
        $email = $this->email($actor);
        $firstname = $this->firstName($actor);
        $phone = $this->phone($actor);
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
        $base = rtrim((string) (request()?->getSchemeAndHttpHost() ?: config('app.url') ?: env('API_PUBLIC_URL')), '/');
        $checkout = $base.'/api/v1/payments/payu/checkout/'.$txnid;

        return [
            'ok' => true,
            'txnid' => $txnid,
            'amountRupees' => (float) $amount,
            'checkoutUrl' => $checkout,
            'webhookUrl' => 'https://api.karnacab.in/api/v1/payments/webhooks/payu',
            'successUrl' => 'https://api.karnacab.in/api/v1/payments/webhooks/payu',
            'failureUrl' => 'https://api.karnacab.in/api/v1/payments/webhooks/payu',
            'gateway' => 'payu',
            'mode' => env('PAYU_MODE', 'test'),
            'sdk' => [
                'key' => $this->key(),
                'txnid' => $txnid,
                'transactionId' => $txnid,
                'amount' => $amount,
                'productinfo' => $productinfo,
                'productInfo' => $productinfo,
                'firstname' => $firstname,
                'firstName' => $firstname,
                'email' => $email,
                'phone' => $phone,
                'hash' => $hash,
                'surl' => $base.'/api/v1/payments/webhooks/payu',
                'furl' => $base.'/api/v1/payments/webhooks/payu',
                'environment' => env('PAYU_MODE', 'test') === 'live' ? '0' : '1',
                'userCredential' => $this->key().':'.$email,
                'android_surl' => $base.'/api/v1/payments/webhooks/payu',
                'android_furl' => $base.'/api/v1/payments/webhooks/payu',
                'ios_surl' => $base.'/api/v1/payments/webhooks/payu',
                'ios_furl' => $base.'/api/v1/payments/webhooks/payu',
                'udf1' => '',
                'udf2' => '',
                'udf3' => '',
                'udf4' => '',
                'udf5' => '',
            ],
        ];
    }

    public function checkoutPage(string $txnid): string
    {
        $row = DB::table('payment_intents')->where('txnid', $txnid)->first();
        abort_unless($row, 404, 'Payment not found');
        $user = User::query()->find($row->user_id);
        abort_unless($user, 404, 'Customer not found');
        $amount = number_format(((int) $row->amount_paise) / 100, 2, '.', '');
        $email = $this->email($user);
        $firstname = $this->firstName($user);
        $phone = $this->phone($user);
        $productinfo = 'KarnaCab wallet top-up';
        $hash = $this->requestHash($txnid, $amount, $productinfo, $firstname, $email);
        $action = $this->payuUrl();
        $returnHost = rtrim((string) (request()?->getSchemeAndHttpHost() ?: config('app.url')), '/');
        $surl = $returnHost.'/api/v1/payments/webhooks/payu';
        $key = e($this->key());
        $firstnameEsc = e($firstname);
        $phoneEsc = e($phone);
        $emailEsc = e($email);
        $txn = e($txnid);
        $productEsc = e($productinfo);

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>PayU checkout</title></head>
<body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;background:#f4f1ea">
  <p>Redirecting to PayU…</p>
  <form id="payu" action="{$action}" method="post">
    <input type="hidden" name="key" value="{$key}">
    <input type="hidden" name="txnid" value="{$txn}">
    <input type="hidden" name="amount" value="{$amount}">
    <input type="hidden" name="productinfo" value="{$productEsc}">
    <input type="hidden" name="firstname" value="{$firstnameEsc}">
    <input type="hidden" name="email" value="{$emailEsc}">
    <input type="hidden" name="phone" value="{$phoneEsc}">
    <input type="hidden" name="surl" value="{$surl}">
    <input type="hidden" name="furl" value="{$surl}">
    <input type="hidden" name="hash" value="{$hash}">
    <input type="hidden" name="udf1" value="">
    <input type="hidden" name="udf2" value="">
    <input type="hidden" name="udf3" value="">
    <input type="hidden" name="udf4" value="">
    <input type="hidden" name="udf5" value="">
    <input type="hidden" name="service_provider" value="payu_paisa">
  </form>
  <script>document.getElementById('payu').submit();</script>
</body></html>
HTML;
    }

    public function sdkHash(Request $request): array
    {
        $hashString = (string) $request->input('hashString', '');
        $hashName = (string) $request->input('hashName', 'payment_hash');
        $missing = [];
        if ($hashString === '') {
            $missing[] = 'hashString';
        }
        if ($this->salt() === '') {
            $missing[] = 'PAYU_SALT';
        }
        abort_if($missing !== [], 422, 'PayU missing parameter: '.implode(', ', $missing));

        return [
            'hashName' => $hashName,
            'hash' => strtolower(hash('sha512', $hashString.$this->salt())),
        ];
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

    private function email(User $actor): string
    {
        $email = (string) ($actor->email ?: '');
        if ($email !== '' && ! str_ends_with($email, '@otp.karnacab.local') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        return 'rider'.$actor->id.'@karnacab.in';
    }

    private function firstName(User $actor): string
    {
        $name = preg_replace('/[^A-Za-z ]/', '', (string) ($actor->name ?: 'Customer')) ?: 'Customer';

        return substr(trim($name), 0, 50) ?: 'Customer';
    }

    private function phone(User $actor): string
    {
        $digits = preg_replace('/\D/', '', (string) ($actor->phone ?: '')) ?: '9999999999';

        return substr($digits, -10);
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
