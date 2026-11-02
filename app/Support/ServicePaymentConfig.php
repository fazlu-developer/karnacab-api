<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service customer advance and driver payable rules.
 * Driver advance is a cap the admin may pay later. It is never sent automatically.
 */
class ServicePaymentConfig
{
    public const KEY = 'service_payment_configs';

    public const COMPANY_PRODUCTS = ['BULK', 'CORPORATE', 'TRAVEL', 'TOUR'];

    public static function companyCollects(string $product): bool
    {
        return in_array(AdvancePayment::normalize($product), self::COMPANY_PRODUCTS, true);
    }

    /**
     * @return array<string, array<string, int|string|bool>>
     */
    public static function all(): array
    {
        $stored = [];
        if (Schema::hasTable('system_settings')) {
            $raw = DB::table('system_settings')->where('key', self::KEY)->value('value');
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $stored = is_array($decoded) ? $decoded : [];
        }
        $out = [];
        foreach (AdvancePayment::PRODUCTS as $product => $label) {
            $row = is_array($stored[$product] ?? null) ? $stored[$product] : [];
            $advance = array_key_exists('advancePercent', $row)
                ? max(0, min(100, (int) $row['advancePercent']))
                : AdvancePayment::percent($product);
            $driverPayable = max(0, min(100, (int) ($row['driverPayablePercent'] ?? 90)));
            $out[$product] = [
                'label' => $label,
                'advancePercent' => $advance,
                'advanceFixedPaise' => max(0, (int) ($row['advanceFixedPaise'] ?? 0)),
                'balanceMilestone' => in_array($row['balanceMilestone'] ?? '', ['confirmation', 'before_start', 'after_complete'], true)
                    ? $row['balanceMilestone']
                    : 'after_complete',
                'dueDays' => max(0, min(90, (int) ($row['dueDays'] ?? 0))),
                'minAdvancePaise' => max(0, (int) ($row['minAdvancePaise'] ?? 0)),
                'maxAdvancePaise' => max(0, (int) ($row['maxAdvancePaise'] ?? 0)),
                'partialEnabled' => (bool) ($row['partialEnabled'] ?? true),
                'creditEnabled' => (bool) ($row['creditEnabled'] ?? false),
                'driverPayablePercent' => $driverPayable,
                'companyCommissionPercent' => max(0, min(100, 100 - $driverPayable)),
                'driverAdvancePercent' => max(0, min(100, (int) ($row['driverAdvancePercent'] ?? 0))),
                'companyCollects' => self::companyCollects($product),
            ];
        }

        return $out;
    }

    public static function forProduct(string $product): array
    {
        $product = AdvancePayment::normalize($product);

        return self::all()[$product] ?? [
            'advancePercent' => 0,
            'driverPayablePercent' => 90,
            'companyCommissionPercent' => 10,
            'driverAdvancePercent' => 0,
            'companyCollects' => false,
            'balanceMilestone' => 'after_complete',
        ];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @return array<string, mixed>
     */
    public static function snapshot(array $quote, string $product, int $totalPaise): array
    {
        $config = self::forProduct($product);
        $total = max(0, $totalPaise);
        $advance = (int) ($config['advanceFixedPaise'] ?? 0);
        if ($advance < 1) {
            $advance = (int) round($total * ((int) $config['advancePercent']) / 100);
        }
        if (($config['minAdvancePaise'] ?? 0) > 0) {
            $advance = max($advance, (int) $config['minAdvancePaise']);
        }
        if (($config['maxAdvancePaise'] ?? 0) > 0) {
            $advance = min($advance, (int) $config['maxAdvancePaise']);
        }
        $advance = max(0, min($total, $advance));
        $driverPayable = (int) round($total * ((int) $config['driverPayablePercent']) / 100);
        $driverAdvanceCap = (int) round($driverPayable * ((int) $config['driverAdvancePercent']) / 100);

        $quote['paymentConfig'] = $config;
        $quote['companyCollects'] = (bool) $config['companyCollects'];
        $quote['customerAdvancePaise'] = $advance;
        $quote['customerBalancePaise'] = max(0, $total - $advance);
        $quote['driverPayablePaise'] = $driverPayable;
        $quote['driverAdvanceCapPaise'] = $driverAdvanceCap;
        $quote['driverAdvancePaidPaise'] = 0;
        $quote['companyCommissionPaise'] = max(0, $total - $driverPayable);

        return $quote;
    }
}
