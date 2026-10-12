<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VehicleCatalogService
{
    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string}>
     */
    public function rideTypes(): array
    {
        $fromDb = $this->fromCatalog('RIDE');
        if ($fromDb !== []) {
            return $fromDb;
        }

        return collect(config('karnacab.vehicle_types'))->map(fn ($row) => [
            'key' => strtoupper((string) $row['key']),
            'label' => (string) $row['label'],
            'seats' => (int) ($row['seats'] ?? 4),
            'group' => 'RIDE',
            'blurb' => '',
        ])->values()->all();
    }

    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string}>
     */
    public function parcelTypes(): array
    {
        $fromDb = $this->fromCatalog('PARCEL');

        return $fromDb !== [] ? $fromDb : [
            ['key' => 'BIKE', 'label' => 'Bike', 'seats' => 1, 'group' => 'PARCEL', 'blurb' => ''],
            ['key' => 'AUTO', 'label' => 'Auto', 'seats' => 3, 'group' => 'PARCEL', 'blurb' => ''],
            ['key' => 'SEDAN', 'label' => 'Car', 'seats' => 4, 'group' => 'PARCEL', 'blurb' => ''],
            ['key' => 'TRAVELLER', 'label' => 'Van', 'seats' => 12, 'group' => 'PARCEL', 'blurb' => ''],
            ['key' => 'TRUCK', 'label' => 'Truck', 'seats' => 0, 'group' => 'PARCEL', 'blurb' => ''],
        ];
    }

    /**
     * @return list<array{key: string, label: string, categories: list<array{key: string, label: string}>}>
     */
    public function families(): array
    {
        $groups = [
            'BIKE' => ['key' => 'BIKE', 'label' => 'Bike', 'categories' => []],
            'AUTO' => ['key' => 'AUTO', 'label' => 'Auto', 'categories' => []],
            'CAR' => ['key' => 'CAR', 'label' => 'Car', 'categories' => []],
        ];
        foreach ($this->rideTypes() as $type) {
            $key = $type['key'];
            $bucket = match ($key) {
                'BIKE' => 'BIKE',
                'AUTO', 'E_RICKSHAW' => 'AUTO',
                default => 'CAR',
            };
            $groups[$bucket]['categories'][] = ['key' => $key, 'label' => $type['label']];
        }
        $groups['BIKE']['label'] = $groups['BIKE']['categories'][0]['label'] ?? 'Bike';

        return array_values(array_filter($groups, fn ($row) => $row['categories'] !== []));
    }

    /**
     * @return list<array{key: string, label: string, seats: int, group: string, blurb: string}>
     */
    private function fromCatalog(string $group): array
    {
        if (! Schema::hasTable('catalog_services') || ! Schema::hasColumn('catalog_services', 'category_key')) {
            return [];
        }
        $q = DB::table('catalog_services')->whereNotNull('category_key')->where('category_key', '!=', '');
        if (Schema::hasColumn('catalog_services', 'active')) {
            $q->where('active', 1);
        }
        if (Schema::hasColumn('catalog_services', 'service_group')) {
            $q->where('service_group', $group);
        }
        $rows = $q->orderBy('sort_order')->orderBy('title')->get();
        $out = [];
        foreach ($rows as $row) {
            $key = strtoupper((string) $row->category_key);
            if ($key === '') {
                continue;
            }
            $out[$key] = [
                'key' => $key,
                'label' => (string) ($row->title ?: $key),
                'seats' => (int) ($row->seats ?? 4),
                'group' => $group,
                'blurb' => (string) ($row->subtitle ?? ''),
            ];
        }

        return array_values($out);
    }
}
