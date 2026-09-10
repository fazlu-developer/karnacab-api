<?php

namespace App\Services;

use App\Models\User;
use App\Support\JwtToken;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
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
        $code = app()->environment('production') ? (string) random_int(100000, 999999) : '123456';
        Cache::put('otp:'.$phone, hash('sha256', $phone.':'.$code), 300);

        return ['ok' => true, 'expiresInSeconds' => 300, 'demo' => ! app()->environment('production')];
    }

    public function verifyOtp(string $phone, string $code, string $role = 'CUSTOMER'): array
    {
        $demo = ! app()->environment('production') && in_array($phone, ['9999999999', '9888888888'], true) && $code === '123456';
        $hash = Cache::get('otp:'.$phone);
        if (! $demo && $hash !== hash('sha256', $phone.':'.$code)) {
            throw ValidationException::withMessages(['code' => 'Invalid OTP']);
        }
        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            $email = $phone.'@otp.karnacab.local';
            $user = User::query()->create([
                'role' => $role,
                'status' => $role === 'DRIVER' ? 'PENDING' : 'ACTIVE',
                'name' => 'KarnaCab user',
                'email' => $email,
                'phone' => $phone,
                'password_hash' => Hash::make(bin2hex(random_bytes(8))),
            ]);
            if ($role === 'DRIVER' && ! $user->driver) {
                DB::table('drivers')->insert([
                    'user_id' => $user->id,
                    'license_no' => 'PENDING',
                    'kyc_status' => 'pending',
                    'duty_status' => 'offline',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $this->issue($user);
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
