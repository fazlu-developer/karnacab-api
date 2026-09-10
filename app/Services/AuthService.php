<?php

namespace App\Services;

use App\Models\User;
use App\Support\JwtToken;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthService
{
    public function register(array $data, string $role = 'CUSTOMER'): array
    {
        $email = strtolower($data['email']);
        if (User::query()->where('email', $email)->exists()) {
            throw new ConflictHttpException('Email already registered');
        }
        $user = User::query()->create([
            'role' => $role,
            'status' => $role === 'DRIVER' ? 'PENDING' : 'ACTIVE',
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password']),
        ]);
        if ($role === 'CUSTOMER') {
            DB::table('wallets')->insert([
                'owner_user_id' => $user->id,
                'owner_type' => 'CUSTOMER',
                'balance_paise' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($role === 'DRIVER') {
            DB::table('drivers')->insert([
                'user_id' => $user->id,
                'license_no' => trim((string) ($data['licenseNo'] ?? 'PENDING')),
                'parcel_enabled' => 1,
                'online' => 0,
                'kyc_status' => 'pending',
                'duty_status' => 'offline',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->issue($user);
    }

    public function login(array $data, ?array $roles = null): array
    {
        $user = User::query()->where('email', strtolower($data['email']))->first();
        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw new UnauthorizedHttpException('', 'Invalid credentials');
        }
        if ($roles && ! in_array($user->role, $roles, true)) {
            throw new UnauthorizedHttpException('', 'Use the correct app for this account');
        }

        return $this->issue($user);
    }

    public function requestOtp(string $phone): array
    {
        $phone = $this->normalizePhone($phone);
        $demo = $this->isDemoPhone($phone);
        $code = $demo ? '123456' : (string) random_int(100000, 999999);
        Cache::put('otp:'.$phone, hash('sha256', $phone.':'.$code), 300);

        $payload = [
            'ok' => true,
            'expiresInSeconds' => 300,
            'demo' => $demo,
        ];
        if ($demo || config('app.debug')) {
            $payload['devCode'] = $code;
        }

        return $payload;
    }

    public function verifyOtp(string $phone, string $code, string $role = 'CUSTOMER'): array
    {
        $phone = $this->normalizePhone($phone);
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $demo = $this->isDemoPhone($phone) && $code === '123456';
        $hash = Cache::get('otp:'.$phone);
        $matches = is_string($hash) && hash_equals($hash, hash('sha256', $phone.':'.$code));
        if (! $demo && ! $matches) {
            abort(422, 'Invalid OTP');
        }

        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            $user = User::query()->create([
                'role' => $role,
                'status' => $role === 'DRIVER' ? 'PENDING' : 'ACTIVE',
                'name' => 'KarnaCab user',
                'email' => $phone.'@otp.karnacab.local',
                'phone' => $phone,
                'password_hash' => Hash::make(bin2hex(random_bytes(8))),
            ]);
        } elseif ($role === 'DRIVER' && $user->role !== 'DRIVER' && $this->isDemoPhone($phone)) {
            $user->update(['role' => 'DRIVER', 'status' => 'PENDING']);
            $user->refresh();
        } elseif ($role === 'DRIVER' && $user->role !== 'DRIVER') {
            abort(403, 'Use the customer app for this number');
        } elseif ($role === 'CUSTOMER' && $user->role === 'DRIVER') {
            abort(403, 'Use the driver app for this number');
        }

        if ($role === 'DRIVER' && ! DB::table('drivers')->where('user_id', $user->id)->exists()) {
            DB::table('drivers')->insert([
                'user_id' => $user->id,
                'license_no' => 'PENDING',
                'kyc_status' => 'pending',
                'duty_status' => 'offline',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->issue($user->fresh());
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) >= 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, -10);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits;
    }

    private function isDemoPhone(string $phone): bool
    {
        return in_array($phone, ['9999999999', '9888888888'], true);
    }

    public function present(User $user): array
    {
        $user->loadMissing('driver');

        return [
            'id' => (string) $user->id,
            'role' => $user->role,
            'status' => $user->status,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'permissions' => Permissions::forRole($user->role),
            'districtId' => $user->district_id,
            'stateId' => $user->state_id,
            'kycStatus' => $user->driver?->kyc_status,
        ];
    }

    public function issue(User $user): array
    {
        return [
            'accessToken' => JwtToken::encode((string) $user->id, $user->role),
            'tokenType' => 'Bearer',
            'user' => $this->present($user),
        ];
    }
}
