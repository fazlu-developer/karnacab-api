<?php

namespace App\Services;

use App\Mail\WelcomeCustomerMail;
use App\Models\User;
use App\Support\JwtToken;
use App\Support\Permissions;
use App\Support\ServiceArea;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
        abort_unless(preg_match('/^[6-9]\d{9}$/', $phone), 422, 'Enter a valid Indian mobile number');
        $code = (string) random_int(100000, 999999);
        Cache::put('otp:'.$phone, hash('sha256', $phone.':'.$code), 300);
        $payload = [
            'ok' => true,
            'expiresInSeconds' => 300,
        ];
        if ($this->shouldShowOtpInApp()) {
            $payload['otp'] = $code;
            $payload['devCode'] = $code;
        }

        return $payload;
    }

    public function verifyOtp(string $phone, string $code, string $role = 'CUSTOMER'): array
    {
        $phone = $this->normalizePhone($phone);
        $code = preg_replace('/\D+/', '', $code) ?? '';
        $hash = Cache::get('otp:'.$phone);
        $matches = is_string($hash) && hash_equals($hash, hash('sha256', $phone.':'.$code));
        if (! $matches) {
            abort(422, 'Invalid OTP');
        }

        $user = User::query()->where('phone', $phone)->first();
        $this->ensureProfileColumns();
        if (! $user) {
            $user = User::query()->create([
                'role' => $role,
                'status' => $role === 'DRIVER' ? 'PENDING' : 'ACTIVE',
                'name' => 'KarnaCab user',
                'email' => $phone.'@otp.karnacab.local',
                'phone' => $phone,
                'password_hash' => Hash::make(bin2hex(random_bytes(8))),
            ]);
            if ($role === 'CUSTOMER' && ! DB::table('wallets')->where('owner_user_id', $user->id)->exists()) {
                DB::table('wallets')->insert([
                    'owner_user_id' => $user->id,
                    'owner_type' => 'CUSTOMER',
                    'balance_paise' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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

        $this->touchSeen($user);

        return $this->issue($user->fresh());
    }

    public function completeProfile(User $user, array $data): array
    {
        $this->ensureProfileColumns();
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        abort_unless(filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Enter a valid email address');
        $taken = User::query()->where('email', $email)->where('id', '!=', $user->id)->exists();
        abort_if($taken, 409, 'Email already registered');
        $name = trim((string) ($data['name'] ?? ''));
        abort_unless(strlen($name) >= 2, 422, 'Enter your full name');
        $gender = strtoupper((string) ($data['gender'] ?? ''));
        abort_unless(in_array($gender, ['MALE', 'FEMALE', 'OTHER'], true), 422, 'Select gender');
        $dob = $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null;
        abort_unless($dob, 422, 'Enter your date of birth');

        $payload = [
            'name' => $name,
            'email' => $email,
            'gender' => $gender,
            'date_of_birth' => $dob,
        ];
        if (Schema::hasColumn('users', 'profile_completed_at')) {
            $payload['profile_completed_at'] = now();
        }
        $user->update($payload);
        $user->refresh();
        $this->touchSeen($user);
        try {
            Mail::to($email)->send(new WelcomeCustomerMail($user));
        } catch (\Throwable) {
        }

        return $this->present($user);
    }

    public function heartbeat(User $user, array $data = []): array
    {
        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) ? (float) $data['lng'] : null;
        $allowed = $lat === null || $lng === null ? null : ServiceArea::allows($lat, $lng);
        $patch = [];
        if ($lat !== null) {
            $patch['last_lat'] = $lat;
        }
        if ($lng !== null) {
            $patch['last_lng'] = $lng;
        }
        if (! empty($data['address'])) {
            $patch['last_address'] = $data['address'];
        }
        if ($lat !== null || $lng !== null) {
            $patch['location_updated_at'] = now();
        }
        if ($patch) {
            $user->update($patch);
        }
        $this->touchSeen($user->fresh());
        $comingSoon = $allowed === false;

        return [
            'ok' => true,
            'allowed' => $allowed !== false,
            'comingSoon' => $comingSoon,
            'serviceArea' => ServiceArea::label($lat, $lng),
            'serviceStates' => ServiceArea::states(),
            'message' => $comingSoon
                ? 'KarnaCab is coming soon in your city. We currently operate in Bihar and Delhi.'
                : null,
        ];
    }

    public function saveDevice(User $user, string $token, string $platform = 'android'): array
    {
        $table = Schema::hasTable('push_devices') ? 'push_devices' : null;
        if ($table === null) {
            $this->ensureDeviceTable();
            $table = 'device_tokens';
        }
        DB::table($table)->updateOrInsert(
            ['token' => $token],
            [
                'user_id' => $user->id,
                'platform' => $platform,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        $this->touchSeen($user);

        return ['ok' => true];
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

    public function present(User $user): array
    {
        $this->touchSeen($user);
        $user->loadMissing('driver');
        $placeholderEmail = str_ends_with((string) $user->email, '@otp.karnacab.local');
        $needsProfile = $user->role === 'CUSTOMER' && (
            $placeholderEmail
            || $user->name === 'KarnaCab user'
            || empty($user->gender)
            || empty($user->date_of_birth)
        );
        $needsLocation = empty($user->last_lat) || empty($user->last_lng);
        $inArea = ServiceArea::allows(
            $user->last_lat !== null ? (float) $user->last_lat : null,
            $user->last_lng !== null ? (float) $user->last_lng : null,
        );
        $next = 'HOME';
        if ($needsProfile) {
            $next = 'PROFILE';
        } elseif ($needsLocation) {
            $next = 'LOCATION';
        } elseif ($user->role === 'CUSTOMER' && ! $inArea) {
            $next = 'COMING_SOON';
        }

        return [
            'id' => (string) $user->id,
            'role' => $user->role,
            'status' => $user->status,
            'name' => $user->name,
            'email' => $placeholderEmail ? '' : $user->email,
            'phone' => $user->phone,
            'gender' => $user->gender,
            'dateOfBirth' => $user->date_of_birth?->toDateString(),
            'avatarUrl' => Schema::hasColumn('users', 'avatar_path')
                ? app(KycDocumentService::class)->previewUrl($user->avatar_path ?? null)
                : null,
            'permissions' => Permissions::forRole($user->role),
            'districtId' => $user->district_id,
            'stateId' => $user->state_id,
            'kycStatus' => $user->driver?->kyc_status,
            'lastLat' => $user->last_lat !== null ? (float) $user->last_lat : null,
            'lastLng' => $user->last_lng !== null ? (float) $user->last_lng : null,
            'lastAddress' => $user->last_address,
            'nextStep' => $next,
            'serviceStates' => ServiceArea::states(),
            'comingSoon' => $user->role === 'CUSTOMER' && ! $needsProfile && ! $needsLocation && ! $inArea,
        ];
    }

    public function issue(User $user): array
    {
        return [
            'accessToken' => JwtToken::encode((string) $user->id, $user->role, (int) ($user->session_epoch ?? 0)),
            'tokenType' => 'Bearer',
            'user' => $this->present($user),
        ];
    }

    public function uploadAvatar(User $user, array $data, $file = null): array
    {
        $this->ensureProfileColumns();
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', fn ($table) => $table->string('avatar_path', 255)->nullable());
        }
        $binary = null;
        $mime = (string) ($data['mime'] ?? 'image/jpeg');
        if ($file) {
            $binary = file_get_contents($file->getRealPath()) ?: null;
            $mime = $file->getMimeType() ?: $mime;
        } elseif (! empty($data['fileBase64'])) {
            $raw = (string) $data['fileBase64'];
            if (str_contains($raw, ',')) {
                $raw = explode(',', $raw, 2)[1];
            }
            $binary = base64_decode($raw, true) ?: null;
        }
        abort_unless($binary, 422, 'Choose a profile photo');
        $ext = str_contains($mime, 'png') ? 'png' : 'jpg';
        $path = 'avatars/'.$user->id.'/'.uniqid('p', true).'.'.$ext;
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $binary);
        User::query()->where('id', $user->id)->update(['avatar_path' => $path, 'updated_at' => now()]);

        return $this->present($user->fresh());
    }

    public function patchProfile(User $user, array $data): array
    {
        $this->ensureProfileColumns();
        $payload = array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'emergency_name' => $data['emergency_name'] ?? $data['emergencyName'] ?? null,
            'emergency_phone' => $data['emergency_phone'] ?? $data['emergencyPhone'] ?? null,
            'gender' => isset($data['gender']) ? strtoupper((string) $data['gender']) : null,
            'date_of_birth' => $data['dateOfBirth'] ?? $data['date_of_birth'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $taken = User::query()->where('email', $email)->where('id', '!=', $user->id)->exists();
            abort_if($taken, 409, 'Email already registered');
            $payload['email'] = $email;
        }
        if ($payload) {
            $user->update($payload);
        }

        return $this->present($user->fresh());
    }

    private function touchSeen(User $user): void
    {
        $this->ensureLastSeenColumn();
        if (Schema::hasColumn('users', 'last_seen_at')) {
            User::query()->where('id', $user->id)->update(['last_seen_at' => now()]);
        }
    }

    private function ensureProfileColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (! Schema::hasColumn('users', 'gender')) {
            Schema::table('users', fn ($table) => $table->string('gender', 16)->nullable());
        }
        if (! Schema::hasColumn('users', 'date_of_birth')) {
            Schema::table('users', fn ($table) => $table->date('date_of_birth')->nullable());
        }
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', fn ($table) => $table->timestamp('last_seen_at')->nullable());
        }
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', fn ($table) => $table->string('avatar_path', 255)->nullable());
        }
    }

    private function ensureLastSeenColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function ($table) {
                $table->timestamp('last_seen_at')->nullable();
            });
        }
    }

    private function shouldShowOtpInApp(): bool
    {
        return (bool) config('karnacab.otp_show_in_app', true);
    }

    private function ensureDeviceTable(): void
    {
        if (Schema::hasTable('device_tokens')) {
            return;
        }
        Schema::create('device_tokens', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 512)->unique();
            $table->string('platform', 24)->default('android');
            $table->timestamps();
            $table->index('user_id');
        });
    }
}
