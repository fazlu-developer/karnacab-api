<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ServiceMaintenance
{
    /**
     * @return array<int, array{everywhere: bool, stateIds: list<int>, districtIds: list<int>}>
     */
    public static function forCatalog(): array
    {
        if (! Schema::hasTable('service_maintenances')) {
            return [];
        }
        $grouped = [];
        foreach (DB::table('service_maintenances')->get() as $row) {
            $id = (int) $row->catalog_service_id;
            $grouped[$id] ??= ['everywhere' => false, 'stateIds' => [], 'districtIds' => []];
            if ($row->state_id === null && $row->district_id === null) {
                $grouped[$id]['everywhere'] = true;
            }
            if ($row->state_id !== null && $row->district_id === null) {
                $grouped[$id]['stateIds'][] = (int) $row->state_id;
            }
            if ($row->district_id !== null) {
                $grouped[$id]['districtIds'][] = (int) $row->district_id;
            }
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function assertOpen(string $product, array $input): void
    {
        $message = self::message($product, $input);
        if ($message !== null) {
            throw new UnprocessableEntityHttpException($message);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function message(string $product, array $input): ?string
    {
        if (! Schema::hasTable('service_maintenances') || ! Schema::hasTable('catalog_services')) {
            return null;
        }
        $product = strtoupper(trim($product));
        if (in_array($product, ['LOCAL', 'TRIP', 'CAB'], true)) {
            $product = 'LOCAL_CAB';
        }
        $service = DB::table('catalog_services')
            ->where(function ($query) {
                $query->where('active', 1)->orWhere('active', true);
            })
            ->get()
            ->first(fn ($row) => self::matches($row, $product));
        if ($service === null) {
            return null;
        }
        $rules = DB::table('service_maintenances')->where('catalog_service_id', $service->id)->get();
        if ($rules->isEmpty()) {
            return null;
        }
        $lat = self::coord($input, ['pickupLat', 'pickup_lat', 'originLat', 'lat']);
        $lng = self::coord($input, ['pickupLng', 'pickup_lng', 'originLng', 'lng']);
        $area = ServiceArea::resolve(
            $lat,
            $lng,
            $input['stateName'] ?? $input['state'] ?? null,
            $input['pickupText'] ?? $input['pickup_text'] ?? $input['address'] ?? null,
        );
        $stateId = (int) ($area['stateId'] ?? 0);
        $districtId = (int) ($area['districtId'] ?? 0);
        $hit = false;
        foreach ($rules as $rule) {
            $ruleState = $rule->state_id === null ? 0 : (int) $rule->state_id;
            $ruleDistrict = $rule->district_id === null ? 0 : (int) $rule->district_id;
            if ($ruleState === 0 && $ruleDistrict === 0) {
                $hit = true;
                break;
            }
            if ($ruleDistrict > 0 && $ruleDistrict === $districtId) {
                $hit = true;
                break;
            }
            if ($ruleState > 0 && $ruleDistrict === 0 && $ruleState === $stateId) {
                $hit = true;
                break;
            }
        }
        if (! $hit) {
            return null;
        }
        $title = trim((string) ($service->title ?? ''));
        if ($title === '') {
            $title = 'This service';
        }

        return $title.' is under maintenance in your area. We are working on it and it will be back soon.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     */
    private static function coord(array $input, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && $input[$key] !== '' && is_numeric($input[$key])) {
                return (float) $input[$key];
            }
        }

        return null;
    }

    private static function matches(object $row, string $product): bool
    {
        $key = strtoupper((string) ($row->category_key ?? ''));
        if ($product === 'LOCAL_CAB') {
            return in_array($key, ['LOCAL_CAB', 'CITY_RIDE'], true);
        }

        return $key === $product;
    }
}
