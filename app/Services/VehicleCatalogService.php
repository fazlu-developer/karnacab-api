<?php

namespace App\Services;

use App\Support\PublicMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VehicleCatalogService
{
    public const TABLE = 'catalog_vehicle_types';

    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string, family: string}>
     */
    public function rideTypes(): array
    {
        $assigned = $this->assignedVehicleTypes('RIDE');
        if ($assigned !== []) {
            return array_values($assigned);
        }

        return array_values(array_filter($this->allTypes(), fn ($row) => ($row['family'] ?? '') !== 'LOADING'));
    }

    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string, family: string}>
     */
    public function parcelTypes(): array
    {
        $assigned = $this->assignedVehicleTypes('PARCEL');
        if ($assigned !== []) {
            return array_values($assigned);
        }

        $fallback = [];
        foreach ($this->allTypes() as $key => $row) {
            if (in_array($row['family'], ['BIKE', 'AUTO', 'LOADING'], true) || in_array($key, ['SEDAN', 'TRAVELLER', 'TRUCK', 'VAN', 'PICKUP'], true)) {
                $row['group'] = 'PARCEL';
                $fallback[$key] = $row;
            }
        }

        return $fallback !== [] ? array_values($fallback) : [
            ['key' => 'BIKE', 'label' => 'Bike', 'seats' => 1, 'group' => 'PARCEL', 'blurb' => '', 'family' => 'BIKE'],
            ['key' => 'AUTO', 'label' => 'Auto', 'seats' => 3, 'group' => 'PARCEL', 'blurb' => '', 'family' => 'AUTO'],
            ['key' => 'SEDAN', 'label' => 'Car', 'seats' => 4, 'group' => 'PARCEL', 'blurb' => '', 'family' => 'CAR'],
            ['key' => 'TRAVELLER', 'label' => 'Van', 'seats' => 12, 'group' => 'PARCEL', 'blurb' => '', 'family' => 'CAR'],
            ['key' => 'TRUCK', 'label' => 'Truck', 'seats' => 0, 'group' => 'PARCEL', 'blurb' => '', 'family' => 'LOADING'],
        ];
    }

    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string, family: string}>
     */
    public function typesForProduct(string $product): array
    {
        $product = strtoupper($product);
        if ($product === 'PARCEL') {
            return $this->parcelTypes();
        }
        $assigned = $this->assignedKeysForProduct($product);
        $all = $this->rideTypes();
        if ($assigned === []) {
            if (in_array($product, ['BULK', 'CORPORATE', 'TRAVEL'], true)) {
                return array_values(array_filter($all, fn ($row) => in_array($row['key'], ['SEDAN', 'SUV', 'TRAVELLER', 'TEMPO'], true)));
            }

            return $all;
        }
        $want = array_fill_keys($assigned, true);

        return array_values(array_filter($all, fn ($row) => isset($want[$row['key']])));
    }

    /**
     * Partner onboarding families from admin catalog (Bike / Auto / Car / Loading).
     *
     * @return list<array{key: string, label: string, categories: list<array{key: string, label: string}>}>
     */
    public function families(): array
    {
        $this->ensureTable();
        $groups = [];
        foreach ($this->allTypes() as $type) {
            $key = strtoupper((string) $type['key']);
            $bucket = in_array($key, ['AMBULANCE', 'BASIC', 'BLS', 'ALS'], true)
                ? 'AMBULANCE'
                : ($type['family'] ?: $this->familyBucket($key));
            if (! isset($groups[$bucket])) {
                $groups[$bucket] = [
                    'key' => $bucket,
                    'label' => $this->familyLabel($bucket),
                    'imageUrl' => $type['familyImageUrl'] ?? '',
                    'categories' => [],
                ];
            } elseif (($groups[$bucket]['imageUrl'] ?? '') === '' && ($type['familyImageUrl'] ?? '') !== '') {
                $groups[$bucket]['imageUrl'] = $type['familyImageUrl'];
            }
            $groups[$bucket]['categories'][] = [
                'key' => $type['key'],
                'label' => $type['label'],
                'imageUrl' => $type['imageUrl'] ?? '',
                'seats' => $type['seats'] ?? 4,
            ];
        }
        if ($groups === []) {
            return [
                ['key' => 'BIKE', 'label' => 'Bike', 'categories' => [['key' => 'BIKE', 'label' => 'Bike'], ['key' => 'SCOOTY', 'label' => 'Scooty']]],
                ['key' => 'AUTO', 'label' => 'Auto', 'categories' => [['key' => 'AUTO', 'label' => 'Auto'], ['key' => 'E_RICKSHAW', 'label' => 'E-Rickshaw']]],
                ['key' => 'CAR', 'label' => 'Car', 'categories' => [
                    ['key' => 'MINI', 'label' => 'Mini'],
                    ['key' => 'SEDAN', 'label' => 'Sedan'],
                    ['key' => 'SUV', 'label' => 'SUV'],
                    ['key' => 'TRAVELLER', 'label' => 'Traveller'],
                ]],
                ['key' => 'LOADING', 'label' => 'Loading', 'categories' => [
                    ['key' => 'PICKUP', 'label' => 'Pickup'],
                    ['key' => 'VAN', 'label' => 'Van'],
                ]],
                ['key' => 'AMBULANCE', 'label' => 'Ambulance', 'categories' => [
                    ['key' => 'AMBULANCE', 'label' => 'Ambulance'],
                    ['key' => 'BASIC', 'label' => 'Basic Patient Transport'],
                    ['key' => 'BLS', 'label' => 'BLS Ambulance'],
                    ['key' => 'ALS', 'label' => 'ALS Ambulance'],
                ]],
            ];
        }

        if (! isset($groups['AMBULANCE'])) {
            $groups['AMBULANCE'] = [
                'key' => 'AMBULANCE',
                'label' => 'Ambulance',
                'imageUrl' => '',
                'categories' => [
                    ['key' => 'AMBULANCE', 'label' => 'Ambulance', 'imageUrl' => '', 'seats' => 2],
                    ['key' => 'BASIC', 'label' => 'Basic Patient Transport', 'imageUrl' => '', 'seats' => 2],
                    ['key' => 'BLS', 'label' => 'BLS Ambulance', 'imageUrl' => '', 'seats' => 2],
                    ['key' => 'ALS', 'label' => 'ALS Ambulance', 'imageUrl' => '', 'seats' => 2],
                ],
            ];
        }

        return array_values($groups);
    }

    public function isVehicleKey(string $key): bool
    {
        $key = strtoupper($key);
        if (isset($this->allTypes()[$key])) {
            return true;
        }

        return in_array($key, ['TRUCK', 'VAN', 'CAR', 'SCOOTY', 'PICKUP', 'AMBULANCE', 'BASIC', 'BLS', 'ALS'], true);
    }

    public function familyBucket(string $key): string
    {
        $key = strtoupper($key);
        if (Schema::hasTable(self::TABLE)) {
            $family = DB::table(self::TABLE)->where('key', $key)->value('family_key');
            if ($family) {
                return strtoupper((string) $family);
            }
        }

        return match ($key) {
            'BIKE', 'SCOOTY' => 'BIKE',
            'AUTO', 'E_RICKSHAW' => 'AUTO',
            'TRUCK', 'VAN', 'PICKUP' => 'LOADING',
            'AMBULANCE', 'BASIC', 'BLS', 'ALS' => 'AMBULANCE',
            default => 'CAR',
        };
    }

    /**
     * @return array<string, array{key: string, label: string, seats: int, group: string, blurb: string, family: string}>
     */
    public function assignedVehicleTypes(string $group = 'RIDE'): array
    {
        $this->ensureVehicleCategoriesColumn();
        if (! Schema::hasTable('catalog_services')) {
            return [];
        }
        $q = DB::table('catalog_services');
        if (Schema::hasColumn('catalog_services', 'active')) {
            $q->where('active', 1);
        }
        if (Schema::hasColumn('catalog_services', 'service_group')) {
            $q->where('service_group', $group);
        }
        $known = $this->allTypes();
        $out = [];
        foreach ($q->get() as $row) {
            foreach ($this->keysFromService($row) as $key) {
                $out[$key] = $known[$key] ?? [
                    'key' => $key,
                    'label' => $key,
                    'seats' => 4,
                    'group' => $group,
                    'blurb' => '',
                    'family' => $this->familyBucket($key),
                ];
                $out[$key]['group'] = $group;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function keysFromService(object $row): array
    {
        $keys = [];
        $raw = $row->vehicle_categories ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $parts = is_array($decoded) ? $decoded : (preg_split('/[\s,]+/', $raw) ?: []);
            foreach ($parts as $part) {
                $key = strtoupper(trim((string) $part));
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }
        $legacy = strtoupper((string) ($row->category_key ?? ''));
        if ($legacy !== '' && $this->isVehicleKey($legacy) && ! $this->isServiceProductKey($legacy)) {
            $keys[] = $legacy;
        }

        return array_values(array_unique($keys));
    }

    public static function fareProduct(string $key, string $title = '', string $slug = ''): string
    {
        $blob = strtoupper(str_replace(['-', ' '], '_', trim($key.' '.$title.' '.$slug)));
        if (str_contains($blob, 'PARCEL')) {
            return 'PARCEL';
        }
        if (str_contains($blob, 'CORPORATE')) {
            return 'CORPORATE';
        }
        if (str_contains($blob, 'BULK')) {
            return 'BULK';
        }
        if (str_contains($blob, 'TRAVEL') && ! str_contains($blob, 'TRANSFER')) {
            return 'TRAVEL';
        }
        if (str_contains($blob, 'MULTI')) {
            return 'MULTI_STOP';
        }
        if (str_contains($blob, 'AIRPORT')) {
            return 'AIRPORT';
        }
        if (str_contains($blob, 'RAIL')) {
            return 'RAILWAY';
        }
        if (str_contains($blob, 'RENTAL') || str_contains($blob, 'PACKAGE')) {
            return 'RENTAL';
        }
        if (str_contains($blob, 'SCHEDULE') || str_contains($blob, 'PREBOOK')) {
            return 'SCHEDULE';
        }
        if (str_contains($blob, 'ROUND')) {
            return 'ROUND_WAY';
        }
        if (str_contains($blob, 'ONE_WAY') || str_contains($blob, 'INTERCITY')) {
            return 'ONE_WAY';
        }
        if (str_contains($blob, 'OUTSTATION') && ! str_contains($blob, 'ROUND')) {
            return 'OUTSTATION';
        }
        if (in_array(strtoupper($key), ['LOCAL_CAB', 'CITY_RIDE', 'CITY', 'TRIP', 'LOCAL', 'CAB'], true)) {
            return 'LOCAL_CAB';
        }
        $key = strtoupper(str_replace(['-', ' '], '_', $key));

        return $key !== '' ? $key : 'LOCAL_CAB';
    }

    /**
     * @return array<string, array{key: string, label: string, seats: int, group: string, blurb: string, family: string}>
     */
    public function allTypes(): array
    {
        $this->ensureTable();
        $out = [];
        if (Schema::hasTable(self::TABLE)) {
            $q = DB::table(self::TABLE)->orderBy('sort_order')->orderBy('label');
            if (Schema::hasColumn(self::TABLE, 'active')) {
                $q->where('active', 1);
            }
            foreach ($q->get() as $row) {
                $key = strtoupper((string) $row->key);
                $out[$key] = [
                    'key' => $key,
                    'label' => (string) $row->label,
                    'seats' => (int) ($row->seats ?? 4),
                    'group' => 'RIDE',
                    'blurb' => (string) ($row->blurb ?? ''),
                    'family' => strtoupper((string) ($row->family_key ?? $this->familyBucket($key))),
                    'imageUrl' => $this->publicUrl($row->image_url ?? null),
                    'familyImageUrl' => $this->publicUrl($row->family_image_url ?? null),
                ];
            }
        }
        if ($out !== []) {
            return $out;
        }

        foreach (config('karnacab.vehicle_types') as $row) {
            $key = strtoupper((string) $row['key']);
            $out[$key] = [
                'key' => $key,
                'label' => (string) $row['label'],
                'seats' => (int) ($row['seats'] ?? 4),
                'group' => 'RIDE',
                'blurb' => '',
                'family' => $this->familyBucket($key),
                'imageUrl' => '',
                'familyImageUrl' => '',
            ];
        }

        return $out;
    }

    public function publicUrl(mixed $path, mixed $version = null): string
    {
        return PublicMedia::presentMediaUrl($path, $version) ?? '';
    }

    public function ensureVehicleCategoriesColumn(): void
    {
        if (! Schema::hasTable('catalog_services')) {
            return;
        }
        if (! Schema::hasColumn('catalog_services', 'vehicle_categories')) {
            Schema::table('catalog_services', function ($table) {
                $table->text('vehicle_categories')->nullable();
            });
        }
    }

    public function ensureTable(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function ($table) {
                $table->id();
                $table->string('family_key', 40);
                $table->string('family_label', 80);
                $table->string('key', 40);
                $table->string('label', 80);
                $table->unsignedTinyInteger('seats')->default(4);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->string('blurb', 180)->nullable();
                $table->string('image_url', 255)->nullable();
                $table->string('family_image_url', 255)->nullable();
                $table->timestamps();
                $table->unique('key');
            });
        }
        if (Schema::hasTable(self::TABLE) && ! Schema::hasColumn(self::TABLE, 'image_url')) {
            Schema::table(self::TABLE, function ($table) {
                $table->string('image_url', 255)->nullable();
            });
        }
        if (Schema::hasTable(self::TABLE) && ! Schema::hasColumn(self::TABLE, 'family_image_url')) {
            Schema::table(self::TABLE, function ($table) {
                $table->string('family_image_url', 255)->nullable();
            });
        }
    }

    /**
     * @return list<string>
     */
    private function assignedKeysForProduct(string $product): array
    {
        $this->ensureVehicleCategoriesColumn();
        if (! Schema::hasTable('catalog_services')) {
            return [];
        }
        $keys = [];
        foreach (DB::table('catalog_services')->get() as $row) {
            if (Schema::hasColumn('catalog_services', 'active') && ! $row->active) {
                continue;
            }
            $mapped = self::fareProduct((string) ($row->category_key ?? ''), (string) ($row->title ?? ''), (string) ($row->slug ?? ''));
            if ($mapped !== $product) {
                continue;
            }
            foreach ($this->keysFromService($row) as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    private function familyLabel(string $key): string
    {
        if (Schema::hasTable(self::TABLE)) {
            $label = DB::table(self::TABLE)->where('family_key', $key)->value('family_label');
            if ($label) {
                return (string) $label;
            }
        }

        return match ($key) {
            'BIKE' => 'Bike',
            'AUTO' => 'Auto',
            'CAR' => 'Car',
            'LOADING' => 'Loading',
            'AMBULANCE' => 'Ambulance',
            default => $key,
        };
    }

    private function isServiceProductKey(string $key): bool
    {
        return in_array($key, ['CITY_RIDE', 'LOCAL_CAB', 'ONE_WAY', 'ROUND_WAY', 'RENTAL', 'SCHEDULE', 'OUTSTATION', 'AIRPORT', 'RAILWAY', 'MULTI_STOP', 'PARCEL', 'TRAVEL', 'BULK', 'CORPORATE'], true);
    }
}
