<?php

namespace App\Services;

use App\Models\User;
use App\Support\PublicMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TerritoryContactService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $actor): array
    {
        if (! Schema::hasTable('head_info')) {
            return [
                'district' => null,
                'state' => null,
                'fallback' => $this->fallback(),
            ];
        }
        $districtId = (int) ($actor->district_id ?? 0);
        $stateId = (int) ($actor->state_id ?? 0);
        if ($actor->role === 'DRIVER' && Schema::hasTable('drivers')) {
            $driver = DB::table('drivers')->where('user_id', $actor->id)->orderByDesc('id')->first();
            if ($driver) {
                $districtId = $districtId > 0 ? $districtId : (int) ($driver->district_id ?? 0);
                $stateId = $stateId > 0 ? $stateId : (int) ($driver->state_id ?? 0);
            }
        }
        if ($districtId > 0 && $stateId < 1 && Schema::hasTable('districts')) {
            $stateId = (int) (DB::table('districts')->where('id', $districtId)->value('state_id') ?? 0);
        }
        $district = $districtId > 0
            ? DB::table('head_info')->where('role', 'DISTRICT_HEAD')->where('district_id', $districtId)->orderByDesc('id')->first()
            : null;
        $state = $stateId > 0
            ? DB::table('head_info')->where('role', 'STATE_HEAD')->where('state_id', $stateId)->orderByDesc('id')->first()
            : null;

        return [
            'district' => $district ? $this->card($district, 'DISTRICT_HEAD') : null,
            'state' => $state ? $this->card($state, 'STATE_HEAD') : null,
            'fallback' => $this->fallback(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(object $row, string $role): array
    {
        $name = '';
        if (! empty($row->user_id) && Schema::hasTable('users')) {
            $name = (string) (DB::table('users')->where('id', $row->user_id)->value('name') ?? '');
        }

        return [
            'role' => $role,
            'name' => $name !== '' ? $name : ($role === 'DISTRICT_HEAD' ? 'District Head' : 'State Head'),
            'supportPhone' => $row->support_phone,
            'email' => $row->email,
            'connectPhone' => $row->connect_phone,
            'address' => $row->address,
            'lat' => $row->lat !== null ? (float) $row->lat : null,
            'lng' => $row->lng !== null ? (float) $row->lng : null,
            'placeName' => $row->place_name,
            'photoUrl' => PublicMedia::presentMediaUrl($row->photo_url ?? null, $row->updated_at ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(): array
    {
        $site = [];
        if (Schema::hasTable('system_settings')) {
            $raw = DB::table('system_settings')->where('key', 'cms_site')->value('value');
            $decoded = json_decode((string) $raw, true);
            $site = is_array($decoded) ? $decoded : [];
        }

        return [
            'title' => 'KarnaRide support',
            'supportPhone' => $site['contactPhone'] ?? '',
            'email' => $site['contactEmail'] ?? '',
            'address' => $site['address'] ?? '',
        ];
    }
}
