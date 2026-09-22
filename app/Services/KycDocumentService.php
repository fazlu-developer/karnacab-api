<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycDocumentService
{
    public const MULTI_TYPES = ['VEHICLE_PHOTO', 'VEHICLE_DRIVER_PHOTO'];

    public const DATED_TYPES = ['LICENSE', 'LICENSE_FRONT', 'LICENSE_BACK', 'INSURANCE', 'POLLUTION', 'PUC', 'PERMIT'];

    public function catalog(): array
    {
        $states = [];
        if (Schema::hasTable('states') && Schema::hasTable('districts')) {
            $states = DB::table('states')->orderBy('name')->get()->map(function ($state) {
                $cities = DB::table('districts')->where('state_id', $state->id)->orderBy('name')->get()
                    ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'code' => $row->code ?? null])
                    ->all();

                return [
                    'id' => (int) $state->id,
                    'name' => $state->name,
                    'code' => $state->code ?? null,
                    'cities' => $cities,
                ];
            })->all();
        }

        $families = [
            ['key' => 'BIKE', 'label' => 'Bike', 'categories' => [['key' => 'BIKE', 'label' => 'Bike']]],
            ['key' => 'AUTO', 'label' => 'Auto', 'categories' => [
                ['key' => 'AUTO', 'label' => 'Auto'],
                ['key' => 'E_RICKSHAW', 'label' => 'E-Rickshaw'],
            ]],
            ['key' => 'CAR', 'label' => 'Car', 'categories' => [
                ['key' => 'MINI', 'label' => 'Mini / Hatchback'],
                ['key' => 'SEDAN', 'label' => 'Sedan'],
                ['key' => 'SUV', 'label' => 'SUV'],
                ['key' => 'TRAVELLER', 'label' => 'Traveller / XL'],
            ]],
        ];

        return [
            'states' => $states,
            'vehicleFamilies' => $families,
            'requiredDocs' => [
                'AADHAAR_FRONT', 'AADHAAR_BACK', 'PAN', 'LICENSE_FRONT', 'LICENSE_BACK',
                'RC', 'INSURANCE', 'VEHICLE_PHOTO', 'VEHICLE_DRIVER_PHOTO', 'LIVE_PHOTO',
            ],
            'datedDocs' => self::DATED_TYPES,
            'optionalDocs' => ['POLLUTION', 'PERMIT'],
            'permitRequiredFor' => ['AUTO', 'CAR'],
        ];
    }

    public function previewUrl(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        if (str_starts_with($key, 'http://') || str_starts_with($key, 'https://')) {
            return $key;
        }

        $base = rtrim((string) (config('app.url') ?: ''), '/');
        $host = request()?->getSchemeAndHttpHost();
        if ($host && (str_contains($base, '127.0.0.1') || str_contains($base, 'localhost') || $base === '')) {
            $base = rtrim($host, '/');
        }
        if ($base === '') {
            $base = 'https://api.karnacab.in';
        }

        return $base.'/storage/'.ltrim($key, '/');
    }

    public function present(DriverDocument $doc): array
    {
        return [
            'id' => (string) $doc->id,
            'type' => $doc->type,
            'status' => $doc->status,
            'expiresAt' => $doc->expires_at,
            'originalName' => $doc->original_name,
            'mime' => $doc->mime,
            'previewUrl' => $this->previewUrl($doc->storage_key),
            'rejectionReason' => $doc->rejection_reason,
        ];
    }

    public function upload(User $actor, array $data, ?UploadedFile $file = null): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $type = strtoupper(trim((string) ($data['type'] ?? '')));
        abort_unless($type !== '', 422, 'Document type is required');

        $binary = null;
        $mime = (string) ($data['mime'] ?? 'image/jpeg');
        $name = (string) ($data['originalName'] ?? strtolower($type).'.jpg');
        if ($file) {
            $binary = file_get_contents($file->getRealPath()) ?: null;
            $mime = $file->getMimeType() ?: $mime;
            $name = $file->getClientOriginalName() ?: $name;
        } elseif (! empty($data['fileBase64'])) {
            $raw = (string) $data['fileBase64'];
            if (str_contains($raw, ',')) {
                $raw = explode(',', $raw, 2)[1];
            }
            $binary = base64_decode($raw, true) ?: null;
        }
        abort_unless($binary, 422, 'Upload an image of this document');
        abort_unless(strlen($binary) < 8 * 1024 * 1024, 422, 'Image must be under 8 MB');

        $ext = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : 'jpg');
        $path = 'kyc/'.$driver->id.'/'.Str::uuid().'.'.$ext;
        $this->writePublicFile($path, $binary);

        $expires = $data['expiresAt'] ?? $data['expires_at'] ?? null;
        if (in_array($type, self::DATED_TYPES, true)) {
            abort_unless($expires, 422, 'Add the expiry date for this document');
        }

        if (! in_array($type, self::MULTI_TYPES, true)) {
            $old = DriverDocument::query()->where('driver_id', $driver->id)->where('type', $type)->get();
            foreach ($old as $row) {
                $this->deleteFile($row->storage_key);
                $row->delete();
            }
        }

        $doc = new DriverDocument();
        $row = [
            'driver_id' => $driver->id,
            'type' => $type,
            'status' => 'pending',
            'storage_key' => $path,
            'original_name' => substr($name, 0, 180),
            'mime' => substr($mime, 0, 80),
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $fill = [];
        foreach ($row as $column => $value) {
            if (Schema::hasColumn('driver_documents', $column)) {
                $fill[$column] = $value;
            }
        }
        $doc->forceFill($fill)->save();

        if (in_array($type, ['LIVE_PHOTO', 'SELFIE', 'PROFILE_PHOTO'], true) && Schema::hasColumn('users', 'avatar_path')) {
            $actor->update(['avatar_path' => $path]);
        }

        return app(DriverOpsService::class)->kycSnapshot($actor);
    }

    public function delete(User $actor, string $id): array
    {
        $driver = Driver::query()->where('user_id', $actor->id)->firstOrFail();
        $doc = DriverDocument::query()->where('driver_id', $driver->id)->where('id', $id)->firstOrFail();
        $this->deleteFile($doc->storage_key);
        $doc->delete();

        return app(DriverOpsService::class)->kycSnapshot($actor);
    }

    public function expireDue(): int
    {
        $due = DriverDocument::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', now()->toDateString())
            ->whereIn('status', ['pending', 'verified', 'under_review'])
            ->get();
        $count = 0;
        $sessions = app(DriverSessionService::class);
        foreach ($due as $doc) {
            $doc->status = 'expired';
            $doc->updated_at = now();
            $doc->save();
            $driver = Driver::query()->with('user')->find($doc->driver_id);
            if (! $driver?->user) {
                continue;
            }
            $reason = $doc->type.' expired on '.$doc->expires_at;
            $mode = $sessions->deactivateOrDefer($driver->user, $driver, $reason);
            $sessions->notifyAdmins(
                'Driver document expired',
                ($driver->user->name ?: 'Driver').' ('.$driver->user->phone.') — '.$reason
                    .($mode === 'deferred' ? '. Session will end after the active trip.' : '. Driver was logged out.'),
                ['type' => 'driver', 'id' => (string) $driver->id],
            );
            $count++;
        }

        return $count;
    }

    public function writePublicFile(string $path, string $binary): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        abort_if($path === '' || str_contains($path, '..'), 422, 'Could not save the attachment');
        $targets = [
            storage_path('app/public/'.$path),
            base_path('../management-admin/storage/app/public/'.$path),
        ];
        $wrote = false;
        foreach ($targets as $full) {
            $dir = dirname($full);
            if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                continue;
            }
            if (@file_put_contents($full, $binary) !== false) {
                $wrote = true;
            }
        }
        abort_unless($wrote, 500, 'Could not save the attachment');
    }

    private function deleteFile(?string $key): void
    {
        if ($key && ! str_starts_with($key, 'http') && Storage::disk('public')->exists($key)) {
            Storage::disk('public')->delete($key);
        }
    }
}
