<?php

namespace App\Support;

use App\Models\User;
use App\Support\PublicMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TerritoryContacts
{
    /**
     * @return array{
     *     state: ?array{id:int,name:string},
     *     district: ?array{id:int,name:string},
     *     stateHead: ?array{name:string,phone:?string,email:?string,photoUrl:?string,role:string},
     *     districtHead: ?array{name:string,phone:?string,email:?string,photoUrl:?string,role:string}
     * }
     */
    public static function forUser(User $user): array
    {
        $stateId = (int) ($user->state_id ?? 0);
        $districtId = (int) ($user->district_id ?? 0);

        if (Schema::hasTable('fleet_owners') && $user->role === 'FLEET_OWNER') {
            $fleet = DB::table('fleet_owners')->where('user_id', $user->id)->orderBy('id')->first();
            if ($fleet) {
                $stateId = (int) ($fleet->state_id ?? $stateId);
                $districtId = (int) ($fleet->district_id ?? $districtId);
            }
        }

        if ($stateId < 1 || $districtId < 1) {
            $area = ServiceArea::resolve(
                $user->last_lat !== null ? (float) $user->last_lat : null,
                $user->last_lng !== null ? (float) $user->last_lng : null,
                null,
                $user->last_address,
            );
            if ($stateId < 1) {
                $stateId = (int) ($area['stateId'] ?? 0);
            }
            if ($districtId < 1) {
                $districtId = (int) ($area['districtId'] ?? 0);
            }
        }

        if ($districtId > 0 && $stateId < 1 && Schema::hasTable('districts')) {
            $stateId = (int) (DB::table('districts')->where('id', $districtId)->value('state_id') ?? 0);
        }

        $state = null;
        if ($stateId > 0 && Schema::hasTable('states')) {
            $row = DB::table('states')->where('id', $stateId)->first(['id', 'name']);
            if ($row) {
                $state = ['id' => (int) $row->id, 'name' => (string) $row->name];
            }
        }

        $district = null;
        if ($districtId > 0 && Schema::hasTable('districts')) {
            $row = DB::table('districts')->where('id', $districtId)->first(['id', 'name']);
            if ($row) {
                $district = ['id' => (int) $row->id, 'name' => (string) $row->name];
            }
        }

        return [
            'state' => $state,
            'district' => $district,
            'stateHead' => self::headInfo('STATE_HEAD', $stateId, null) ?? self::operator('STATE_HEAD', 'state_id', $stateId),
            'districtHead' => self::headInfo('DISTRICT_HEAD', $stateId, $districtId) ?? self::operator('DISTRICT_HEAD', 'district_id', $districtId),
        ];
    }

    /**
     * Saved territory contact (head_info), including photo, phone, and address.
     *
     * @return array{name:string,phone:?string,email:?string,photoUrl:?string,address:?string,role:string}|null
     */
    private static function headInfo(string $role, int $stateId, ?int $districtId): ?array
    {
        if (! Schema::hasTable('head_info')) {
            return null;
        }
        $q = DB::table('head_info')->where('role', $role);
        if ($role === 'DISTRICT_HEAD') {
            if (($districtId ?? 0) < 1) {
                return null;
            }
            $q->where('district_id', $districtId);
        } elseif ($stateId > 0) {
            $q->where('state_id', $stateId);
        } else {
            return null;
        }
        $row = $q->orderByDesc('id')->first();
        if (! $row) {
            return null;
        }
        $name = '';
        if (! empty($row->user_id) && Schema::hasTable('users')) {
            $name = (string) (DB::table('users')->where('id', $row->user_id)->value('name') ?? '');
        }
        $support = trim((string) ($row->support_phone ?? ''));
        $connect = trim((string) ($row->connect_phone ?? ''));
        $phone = $support !== '' ? $support : $connect;

        return [
            'name' => $name !== '' ? $name : ($role === 'DISTRICT_HEAD' ? 'District Head' : 'State Head'),
            'phone' => $phone !== '' ? $phone : null,
            'supportPhone' => $support !== '' ? $support : null,
            'connectPhone' => $connect !== '' ? $connect : null,
            'email' => isset($row->email) && trim((string) $row->email) !== '' ? (string) $row->email : null,
            'photoUrl' => PublicMedia::presentMediaUrl($row->photo_url ?? null, $row->updated_at ?? null),
            'address' => isset($row->address) && trim((string) $row->address) !== '' ? (string) $row->address : null,
            'placeName' => isset($row->place_name) && trim((string) $row->place_name) !== '' ? (string) $row->place_name : null,
            'lat' => isset($row->lat) ? (float) $row->lat : null,
            'lng' => isset($row->lng) ? (float) $row->lng : null,
            'role' => $role,
        ];
    }

    /**
     * @return array{name:string,phone:?string,email:?string,photoUrl:?string,role:string}|null
     */
    private static function operator(string $role, string $column, int $id): ?array
    {
        if ($id < 1 || ! Schema::hasTable('users')) {
            return null;
        }
        $cols = ['name', 'phone'];
        if (Schema::hasColumn('users', 'email')) {
            $cols[] = 'email';
        }
        if (Schema::hasColumn('users', 'avatar_url')) {
            $cols[] = 'avatar_url';
        } elseif (Schema::hasColumn('users', 'photo_url')) {
            $cols[] = 'photo_url';
        }
        $row = DB::table('users')
            ->where('role', $role)
            ->where($column, $id)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->first($cols);
        if (! $row && $role === 'DISTRICT_HEAD') {
            $row = DB::table('users')
                ->where('role', 'FRANCHISE')
                ->where($column, $id)
                ->where('status', 'ACTIVE')
                ->orderBy('id')
                ->first($cols);
        }
        if (! $row) {
            return null;
        }
        $photo = $row->avatar_url ?? $row->photo_url ?? null;

        return [
            'name' => (string) $row->name,
            'phone' => $row->phone ? (string) $row->phone : null,
            'email' => isset($row->email) && $row->email ? (string) $row->email : null,
            'photoUrl' => $photo ? (PublicMedia::storagePath((string) $photo) ?? (string) $photo) : null,
            'role' => $role,
        ];
    }
}
