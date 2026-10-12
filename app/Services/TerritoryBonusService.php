<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TerritoryBonusService
{
    public function ensureTable(): void
    {
        if (Schema::hasTable('territory_bonuses')) {
            return;
        }
        Schema::create('territory_bonuses', function ($table) {
            $table->id();
            $table->string('audience', 16);
            $table->string('kind', 16);
            $table->unsignedInteger('state_id')->nullable();
            $table->unsignedInteger('district_id')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('amount_rupees')->default(0);
            $table->unsignedInteger('required_rides')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array{enabled: bool, amountRupees: int, requiredRides: int, source: string}
     */
    public function resolve(string $audience, string $kind, ?int $stateId, ?int $districtId): array
    {
        $this->ensureTable();
        $fallback = $this->globalFallback($audience, $kind);
        if (! Schema::hasTable('territory_bonuses')) {
            return $fallback;
        }
        $rows = DB::table('territory_bonuses')
            ->where('audience', strtoupper($audience))
            ->where('kind', strtolower($kind))
            ->orderByDesc('id')
            ->get();
        $district = $districtId
            ? $rows->first(fn ($row) => (int) $row->district_id === (int) $districtId)
            : null;
        $state = $stateId
            ? $rows->first(fn ($row) => (int) ($row->state_id ?? 0) === (int) $stateId && empty($row->district_id))
            : null;
        $global = $rows->first(fn ($row) => empty($row->state_id) && empty($row->district_id));
        $pick = $district ?: ($state ?: $global);
        if (! $pick) {
            return $fallback;
        }
        $enabled = (bool) $pick->enabled && (int) $pick->amount_rupees > 0;

        return [
            'enabled' => $enabled,
            'amountRupees' => $enabled ? (int) $pick->amount_rupees : 0,
            'requiredRides' => (int) ($pick->required_rides ?: $fallback['requiredRides']),
            'source' => $district ? 'district' : ($state ? 'state' : 'territory_global'),
        ];
    }

    /**
     * @return array{enabled: bool, amountRupees: int, requiredRides: int, source: string}
     */
    public function forUser(User $actor, string $kind): array
    {
        $audience = $actor->role === 'DRIVER' ? 'DRIVER' : 'CUSTOMER';

        return $this->resolve($audience, $kind, $actor->state_id ? (int) $actor->state_id : null, $actor->district_id ? (int) $actor->district_id : null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicOffers(): array
    {
        $this->ensureTable();
        $settings = app(RideSettingsService::class);
        $rows = Schema::hasTable('territory_bonuses')
            ? DB::table('territory_bonuses')
                ->leftJoin('states', 'states.id', '=', 'territory_bonuses.state_id')
                ->leftJoin('districts', 'districts.id', '=', 'territory_bonuses.district_id')
                ->orderByDesc('territory_bonuses.id')
                ->get([
                    'territory_bonuses.*',
                    'states.name as state_name',
                    'districts.name as district_name',
                ])
            : collect();

        $mapped = $rows->map(fn ($row) => [
            'audience' => $row->audience,
            'kind' => $row->kind,
            'stateId' => $row->state_id ? (int) $row->state_id : null,
            'districtId' => $row->district_id ? (int) $row->district_id : null,
            'stateName' => $row->state_name,
            'districtName' => $row->district_name,
            'enabled' => (bool) $row->enabled,
            'amountRupees' => (int) $row->amount_rupees,
            'requiredRides' => (int) ($row->required_rides ?? 0),
        ])->all();
        $mapped[] = [
            'audience' => 'DRIVER',
            'kind' => 'joining',
            'stateId' => null,
            'districtId' => null,
            'stateName' => null,
            'districtName' => 'All (default)',
            'enabled' => $settings->driverWelcomeBonusEnabled(),
            'amountRupees' => $settings->driverWelcomeBonusRupees(),
            'requiredRides' => 0,
        ];

        return $mapped;
    }

    /**
     * @return array{enabled: bool, amountRupees: int, requiredRides: int, source: string}
     */
    private function globalFallback(string $audience, string $kind): array
    {
        $settings = app(RideSettingsService::class);
        $audience = strtoupper($audience);
        $kind = strtolower($kind);
        if ($audience === 'DRIVER' && $kind === 'joining') {
            $on = $settings->driverWelcomeBonusEnabled();
            $amt = $settings->driverWelcomeBonusRupees();

            return ['enabled' => $on && $amt > 0, 'amountRupees' => $on ? $amt : 0, 'requiredRides' => 0, 'source' => 'global'];
        }
        if ($audience === 'DRIVER' && $kind === 'referral') {
            $amt = $settings->driverReferralBonusRupees();

            return ['enabled' => $amt > 0, 'amountRupees' => $amt, 'requiredRides' => $settings->driverReferralRequiredRides(), 'source' => 'global'];
        }
        if ($audience === 'CUSTOMER' && $kind === 'joining') {
            $amt = $settings->customerJoiningCreditRupees();

            return ['enabled' => $amt > 0, 'amountRupees' => $amt, 'requiredRides' => 0, 'source' => 'global'];
        }
        $amt = $settings->customerReferralCreditRupees();

        return ['enabled' => $amt > 0, 'amountRupees' => $amt, 'requiredRides' => 0, 'source' => 'global'];
    }
}
