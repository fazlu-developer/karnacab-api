<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears customers, drivers, vehicles and all bookings for a clean go-live.
 * Keeps geo, fares, catalog, CMS, travel/corporate packages, and ADMIN / SUPER_ADMIN accounts.
 */
class GoLiveFreshSeeder extends Seeder
{
    private const KEEP_TABLES = [
        '_prisma_migrations',
        'migrations',
        'ops_migrations',
        'web_migrations',
        'states',
        'districts',
        'fare_rules',
        'bulk_rate_rules',
        'parcel_fare_rules',
        'commission_rules',
        'catalog_services',
        'cms_pages',
        'system_settings',
        'support_faqs',
        'travel_packages',
        'corporate_plans',
        'notification_event_templates',
        'ops_users',
        'ops_user_permissions',
        'ops_sessions',
        'ops_state_head_assignments',
        'users',
    ];

    private const KEEP_ROLES = ['ADMIN', 'SUPER_ADMIN'];

    public function run(): void
    {
        $this->wipeTransactional();
        $this->keepOnlyOperators();
        $this->seedOffers();
        $this->seedCoupons();
        $this->seedBanners();
        $this->call(TravelAndCorporatePackagesSeeder::class);
        $this->command?->info('Go-live database is clean. Admin logins kept. Packages, banners and offers seeded.');
    }

    private function wipeTransactional(): void
    {
        $name = 'Tables_in_'.DB::getDatabaseName();
        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => $row->$name)
            ->reject(fn ($table) => in_array($table, self::KEEP_TABLES, true))
            ->values();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function keepOnlyOperators(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }
        DB::table('users')->whereNotIn('role', self::KEEP_ROLES)->delete();
    }

    private function seedOffers(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $offers = [
            [
                'title' => 'FLAT 50% OFF',
                'subtitle' => 'First city ride up to ₹75',
                'code' => 'KARNA50',
                'linkUrl' => '',
                'imageUrl' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1400&q=80',
            ],
            [
                'title' => 'Airport Transfer',
                'subtitle' => 'Meet & greet from ₹399',
                'code' => 'AIRPORT99',
                'linkUrl' => '',
                'imageUrl' => 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1400&q=80',
            ],
            [
                'title' => 'Weekend Tours',
                'subtitle' => 'Manali & Nainital packages',
                'code' => 'TOUR10',
                'linkUrl' => '',
                'imageUrl' => 'https://images.unsplash.com/photo-1506905925346-21bda4d32df4?auto=format&fit=crop&w=1400&q=80',
            ],
            [
                'title' => 'Corporate Desk',
                'subtitle' => 'GST invoices for your company',
                'code' => 'CORP5',
                'linkUrl' => '',
                'imageUrl' => 'https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?auto=format&fit=crop&w=1400&q=80',
            ],
        ];
        $this->putSetting('cms_home_offers', json_encode($offers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->putSetting('cms_home_promo', json_encode([
            'title' => 'Ride more, save more',
            'cta' => 'View offers',
            'subtitle' => 'Fresh KarnaRide launch offers on city, airport and tours',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function seedCoupons(): void
    {
        if (! Schema::hasTable('coupons')) {
            return;
        }
        $now = now();
        $rows = [
            ['code' => 'KARNA50', 'title' => 'First ride 50% off', 'subtitle' => 'New customers, city rides', 'kind' => 'percent', 'percent' => 50, 'max_discount_paise' => 7500, 'min_fare_paise' => 8000, 'product' => 'LOCAL_CAB'],
            ['code' => 'AIRPORT99', 'title' => 'Airport ₹99 off', 'subtitle' => 'Airport transfers', 'kind' => 'flat', 'percent' => 0, 'max_discount_paise' => 9900, 'min_fare_paise' => 30000, 'product' => 'AIRPORT'],
            ['code' => 'TOUR10', 'title' => 'Tours 10% off', 'subtitle' => 'Travel packages', 'kind' => 'percent', 'percent' => 10, 'max_discount_paise' => 200000, 'min_fare_paise' => 500000, 'product' => 'TRAVEL'],
            ['code' => 'CORP5', 'title' => 'Corporate 5%', 'subtitle' => 'Business travel', 'kind' => 'percent', 'percent' => 5, 'max_discount_paise' => 50000, 'min_fare_paise' => 20000, 'product' => 'CORPORATE'],
        ];
        foreach ($rows as $row) {
            $payload = array_merge($row, [
                'starts_on' => $now,
                'ends_on' => $now->copy()->addYear(),
                'active' => 1,
                'audience' => 'all',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('coupons')->insert(array_filter(
                $payload,
                fn ($key) => Schema::hasColumn('coupons', $key),
                ARRAY_FILTER_USE_KEY
            ));
        }
    }

    private function seedBanners(): void
    {
        if (! Schema::hasTable('ad_campaigns')) {
            return;
        }
        if (! Schema::hasColumn('ad_campaigns', 'image_url')) {
            Schema::table('ad_campaigns', function ($table) {
                $table->string('image_url', 500)->nullable();
            });
        }
        $now = now();
        $banners = [
            [
                'title' => 'KarnaRide Launch — Ride Smart',
                'business_name' => 'KarnaRide',
                'category' => 'local_businesses',
                'image' => 'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?auto=format&fit=crop&w=1600&q=80',
                'cta' => 'https://karnacab.in',
            ],
            [
                'title' => 'Safe Airport Pickups',
                'business_name' => 'KarnaRide Airport',
                'category' => 'local_businesses',
                'image' => 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1600&q=80',
                'cta' => 'https://karnacab.in',
            ],
            [
                'title' => 'Himalayan Weekend Packages',
                'business_name' => 'KarnaRide Tours',
                'category' => 'local_businesses',
                'image' => 'https://images.unsplash.com/photo-1626621341517-bbf3d9990a23?auto=format&fit=crop&w=1600&q=80',
                'cta' => 'https://karnacab.in',
            ],
        ];
        foreach ($banners as $banner) {
            $row = [
                'advertiser_user_id' => null,
                'business_name' => $banner['business_name'],
                'title' => $banner['title'],
                'category' => $banner['category'],
                'campaign_type' => 'banner',
                'target_city' => null,
                'state_id' => null,
                'district_id' => null,
                'starts_on' => $now,
                'ends_on' => $now->copy()->addYear(),
                'budget_paise' => 5000000,
                'budget_used_paise' => 0,
                'status' => 'published',
                'published_at' => $now,
                'cta_url' => $banner['cta'],
                'image_url' => $banner['image'],
                'impressions' => 0,
                'clicks' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            DB::table('ad_campaigns')->insert(array_filter(
                $row,
                fn ($key) => Schema::hasColumn('ad_campaigns', $key),
                ARRAY_FILTER_USE_KEY
            ));
        }
    }

    private function putSetting(string $key, string $value): void
    {
        $row = ['key' => $key, 'value' => $value];
        if (Schema::hasColumn('system_settings', 'updated_at')) {
            $row['updated_at'] = now();
        }
        $exists = DB::table('system_settings')->where('key', $key)->first();
        if ($exists) {
            DB::table('system_settings')->where('key', $key)->update(array_diff_key($row, ['key' => true]));

            return;
        }
        if (Schema::hasColumn('system_settings', 'created_at')) {
            $row['created_at'] = now();
        }
        DB::table('system_settings')->insert($row);
    }
}
