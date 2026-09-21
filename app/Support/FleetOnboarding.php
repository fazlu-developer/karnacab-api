<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FleetOnboarding
{
    public const COMPANY_TYPES = [
        'PRIVATE_LIMITED',
        'LLP',
        'PARTNERSHIP',
        'PROPRIETORSHIP',
        'INDIVIDUAL',
    ];

    public const COMPANY_DOCS = [
        'GST',
        'PAN',
        'COMPANY_REG',
        'ADDRESS_PROOF',
        'BANK',
        'TRADE_LICENSE',
    ];

    public static function ensureColumns(): void
    {
        if (! Schema::hasTable('fleet_owners')) {
            return;
        }
        $columns = [
            'company_type' => fn ($table) => $table->string('company_type', 40)->nullable(),
            'kyc_status' => fn ($table) => $table->string('kyc_status', 32)->nullable(),
            'pan' => fn ($table) => $table->string('pan', 20)->nullable(),
            'contact_email' => fn ($table) => $table->string('contact_email', 180)->nullable(),
            'contact_phone' => fn ($table) => $table->string('contact_phone', 20)->nullable(),
            'documents_json' => fn ($table) => $table->longText('documents_json')->nullable(),
            'submitted_at' => fn ($table) => $table->timestamp('submitted_at')->nullable(),
            'verified_at' => fn ($table) => $table->timestamp('verified_at')->nullable(),
        ];
        foreach ($columns as $name => $define) {
            if (Schema::hasColumn('fleet_owners', $name)) {
                continue;
            }
            Schema::table('fleet_owners', function ($table) use ($define) {
                $define($table);
            });
        }
    }

    public static function ensureRow(int $userId): object
    {
        self::ensureColumns();
        $row = DB::table('fleet_owners')->where('user_id', $userId)->orderBy('id')->first();
        if ($row) {
            return $row;
        }
        $payload = [
            'user_id' => $userId,
            'trade_name' => null,
            'status' => 'PENDING',
        ];
        if (Schema::hasColumn('fleet_owners', 'kyc_status')) {
            $payload['kyc_status'] = 'pending';
        }
        if (Schema::hasColumn('fleet_owners', 'created_at')) {
            $payload['created_at'] = now();
        }
        if (Schema::hasColumn('fleet_owners', 'updated_at')) {
            $payload['updated_at'] = now();
        }
        $id = DB::table('fleet_owners')->insertGetId($payload);

        return DB::table('fleet_owners')->where('id', $id)->first();
    }

    public static function isActive(?object $fleet): bool
    {
        if ($fleet === null) {
            return false;
        }
        $status = strtoupper((string) ($fleet->status ?? 'ACTIVE'));
        $kyc = strtolower((string) ($fleet->kyc_status ?? ''));
        if (in_array($kyc, ['pending', 'under_review', 'rejected', 'submitted'], true)) {
            return false;
        }
        if (in_array($status, ['PENDING', 'SUSPENDED', 'REJECTED'], true)) {
            return false;
        }

        return true;
    }

    public static function nextStep(?object $fleet): string
    {
        if (self::isActive($fleet)) {
            return 'HOME';
        }
        $kyc = strtolower((string) ($fleet?->kyc_status ?? 'pending'));
        if ($kyc === 'rejected') {
            return 'FLEET_COMPANY';
        }
        if ($kyc === 'under_review' || ! empty($fleet?->submitted_at)) {
            return 'FLEET_REVIEW';
        }
        $name = trim((string) ($fleet?->trade_name ?? ''));
        $type = trim((string) ($fleet?->company_type ?? ''));
        if ($name === '' || $type === '') {
            return 'FLEET_COMPANY';
        }

        return 'FLEET_DOCS';
    }

    public static function documents(?object $fleet): array
    {
        if ($fleet === null || empty($fleet->documents_json)) {
            return [];
        }
        $decoded = json_decode((string) $fleet->documents_json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
