<?php

namespace App\Services;

use App\Models\CatalogService;
use App\Models\CmsPage;
use Illuminate\Support\Facades\DB;

class CmsCatalogService
{
    public function site(): array
    {
        $pages = CmsPage::query()->where('published', 1)->orderBy('sort_order')->get();
        $presented = $pages->map(fn ($row) => $this->presentPage($row))->all();
        $settings = DB::table('system_settings')->whereIn('key', ['cms_site', 'cms_home_promo'])->pluck('value', 'key');

        return [
            'site' => $this->json($settings['cms_site'] ?? null),
            'promo' => $this->json($settings['cms_home_promo'] ?? null),
            'pages' => $presented,
            'nav' => $this->nav($presented),
            'catalog' => array_merge($this->catalog(), [
                'rentalPackages' => app(FareService::class)->rentalPackages(),
            ]),
            'faqs' => DB::table('support_faqs')
                ->where('audience', 'customer')
                ->where('active', 1)
                ->orderBy('sort_order')
                ->get(['question', 'answer'])
                ->all(),
        ];
    }

    public function page(string $slug): array
    {
        $row = CmsPage::query()->where('slug', $slug)->where('published', 1)->firstOrFail();

        return $this->presentPage($row);
    }

    public function adminList(): array
    {
        return ['pages' => CmsPage::query()->orderBy('sort_order')->get()->map(fn ($row) => $this->presentPage($row))->all()];
    }

    public function catalog(): array
    {
        $services = CatalogService::query()->where('active', 1)->orderBy('sort_order')->get();
        $ride = $services->where('service_group', 'RIDE')->values();
        $parcel = $services->where('service_group', 'PARCEL')->values();
        $packages = DB::table('travel_packages')->where('status', 'PUBLISHED')->orderBy('id')->get();
        $districts = DB::table('districts')->orderBy('name')->get(['id', 'name', 'code']);

        return [
            'tabs' => [
                ['key' => 'ride', 'title' => 'KarnaCab'],
                ['key' => 'parcel', 'title' => 'Parcel'],
            ],
            'rideServices' => $ride->map(fn ($row) => $this->service($row))->all(),
            'parcelServices' => $parcel->map(fn ($row) => $this->service($row))->all(),
            'promo' => $this->json(DB::table('system_settings')->where('key', 'cms_home_promo')->value('value')),
            'districts' => $districts,
            'packages' => $packages->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->title,
                'destination' => $row->destination ?? null,
                'pricePaise' => $row->price_paise ?? null,
            ])->all(),
            'rideTypes' => collect(config('karnacab.ride_types'))->map(fn ($row) => ['key' => $row['key'], 'title' => $row['label']])->all(),
            'vehicleTypes' => collect(config('karnacab.vehicle_types'))->map(fn ($row) => ['key' => $row['key'], 'title' => $row['label']])->all(),
            'dummyAccounts' => [
                'customer' => ['phone' => '9999999999', 'otp' => '123456', 'name' => 'Fazlu'],
                'driver' => ['phone' => '9888888888', 'otp' => '123456', 'name' => 'Rakesh Kumar'],
            ],
        ];
    }

    public function catalogPublic(): array
    {
        $payload = $this->catalog();
        unset($payload['dummyAccounts']);

        return $payload;
    }

    private function presentPage($row): array
    {
        return [
            'id' => (string) $row->id,
            'slug' => $row->slug,
            'path' => $row->slug === 'home' ? '/' : '/'.$row->slug,
            'title' => $row->title,
            'eyebrow' => $row->eyebrow,
            'seoTitle' => $row->seo_title ?: $row->title.' | KarnaCab',
            'seoDescription' => $row->seo_description ?: $row->lede,
            'lede' => $row->lede,
            'body' => $row->body,
            'template' => $row->template,
            'leadType' => $row->lead_type,
            'registerKind' => $row->register_kind,
            'productKey' => $row->product_key,
            'navGroup' => $row->nav_group,
            'navLabel' => $row->nav_label,
            'sortOrder' => (int) $row->sort_order,
            'published' => (bool) $row->published,
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
        ];
    }

    private function nav(array $pages): array
    {
        return collect(['primary', 'rides', 'services', 'company', 'legal'])->map(function (string $group) use ($pages) {
            $items = collect($pages)
                ->filter(fn ($page) => ($page['navGroup'] ?? '') === $group && ($page['slug'] ?? '') !== 'home')
                ->sortBy('sortOrder')
                ->values()
                ->map(fn ($page) => ['slug' => $page['slug'], 'path' => $page['path'], 'label' => $page['navLabel']])
                ->all();

            return ['group' => $group, 'items' => $items];
        })->all();
    }

    private function service($row): array
    {
        return [
            'id' => $row->id,
            'slug' => $row->slug,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'group' => $row->service_group,
            'key' => strtoupper(str_replace('-', '_', (string) $row->slug)),
        ];
    }

    private function json(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
