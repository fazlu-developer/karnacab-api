<?php

namespace App\Services;

use App\Models\CatalogService;
use App\Models\CmsPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CmsCatalogService
{
    public function site(): array
    {
        $this->ensureAppPages();
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
            'offers' => app(RideSettingsService::class)->present(),
            'faqs' => Schema::hasTable('support_faqs')
                ? DB::table('support_faqs')
                    ->where('audience', 'customer')
                    ->where('active', 1)
                    ->orderBy('sort_order')
                    ->get(['question', 'answer'])
                    ->all()
                : [],
        ];
    }

    public function page(string $slug): array
    {
        $this->ensureAppPages();
        $row = CmsPage::query()->where('slug', $slug)->where('published', 1)->firstOrFail();

        return $this->presentPage($row);
    }

    public function appPages(): array
    {
        $this->ensureAppPages();
        $slugs = array_column(self::appPageCatalog(), 'slug');
        $pages = CmsPage::query()->whereIn('slug', $slugs)->where('published', 1)->orderBy('sort_order')->get()
            ->map(fn ($row) => $this->presentPage($row))
            ->all();

        return ['pages' => $pages];
    }

    /**
     * @return list<array{slug: string, title: string, lede: string, body: string}>
     */
    public static function appPageCatalog(): array
    {
        return [
            [
                'slug' => 'about-us',
                'title' => 'About Us',
                'lede' => 'KARNACAB TRANSPORT SERVICE PRIVATE LIMITED, consumer brand KarnaRide.',
                'body' => self::legalBody('about'),
            ],
            [
                'slug' => 'privacy-policy',
                'title' => 'Privacy Policy',
                'lede' => 'How KarnaRide collects, uses, shares and protects your information, including location.',
                'body' => self::legalBody('privacy-policy'),
            ],
            [
                'slug' => 'terms-conditions',
                'title' => 'Terms & Conditions',
                'lede' => 'Binding terms for the KarnaRide website and customer app.',
                'body' => self::legalBody('terms'),
            ],
            [
                'slug' => 'return-refund',
                'title' => 'Cancellation & Refund',
                'lede' => 'Cancellation windows and how qualified refunds are paid.',
                'body' => self::legalBody('return-refund'),
            ],
            [
                'slug' => 'software-license',
                'title' => 'Software License',
                'lede' => 'Licence to use the KarnaRide customer application.',
                'body' => self::legalBody('software-license'),
            ],
        ];
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
                ['key' => 'ride', 'title' => 'KarnaRide'],
                ['key' => 'parcel', 'title' => 'Parcel'],
            ],
            'rideServices' => $ride->map(fn ($row) => $this->service($row))->all(),
            'parcelServices' => $parcel->map(fn ($row) => $this->service($row))->all(),
            'promo' => $this->json(DB::table('system_settings')->where('key', 'cms_home_promo')->value('value')),
            'districts' => $districts,
            'packages' => $packages->map(fn ($row) => [
                'id' => $row->id,
                'title' => $row->title ?? $row->name,
                'destination' => $row->destination ?? null,
                'origin' => $row->origin ?? 'Saharsa, Bihar',
                'region' => $row->region ?? null,
                'category' => $row->category ?? null,
                'durationLabel' => $row->duration_label ?? null,
                'pricePaise' => $row->price_paise ?? null,
                'priceRupees' => ((int) ($row->price_paise ?? 0)) / 100,
                'imageUrl' => $row->image_url ?? null,
                'popular' => (bool) ($row->popular ?? false),
            ])->all(),
            'rideTypes' => collect(config('karnacab.ride_types'))->map(fn ($row) => ['key' => $row['key'], 'title' => $row['label']])->all(),
            'vehicleTypes' => collect(config('karnacab.vehicle_types'))->map(fn ($row) => ['key' => $row['key'], 'title' => $row['label']])->all(),
            'homeOffers' => $this->homeOffers(),
        ];
    }

    public function catalogPublic(): array
    {
        $payload = $this->catalog();

        return $payload;
    }

    private function presentPage($row): array
    {
        $html = $this->bodyHtml($row->body);

        return [
            'id' => (string) $row->id,
            'slug' => $row->slug,
            'path' => $row->slug === 'home' ? '/' : '/'.$row->slug,
            'title' => $row->title,
            'eyebrow' => $row->eyebrow,
            'seoTitle' => $row->seo_title ?: $row->title.' | KarnaRide',
            'seoDescription' => $row->seo_description ?: $row->lede,
            'lede' => $row->lede,
            'description' => $row->lede,
            'body' => $row->body,
            'bodyHtml' => $html,
            'imageUrl' => $this->publicUpload($row->image_url ?? null),
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

    private function bodyHtml(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (! is_array($body)) {
            return '';
        }
        if (isset($body['html'])) {
            return (string) $body['html'];
        }
        if (isset($body['text'])) {
            return (string) $body['text'];
        }
        $parts = [];
        foreach ($body as $block) {
            if (is_string($block)) {
                $parts[] = $block;
            } elseif (is_array($block)) {
                $parts[] = (string) ($block['html'] ?? $block['text'] ?? $block['lede'] ?? '');
            }
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    public function ensureAppPages(): void
    {
        if (! Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function ($table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('title');
                $table->string('eyebrow')->nullable();
                $table->string('seo_title')->nullable();
                $table->string('seo_description', 500)->nullable();
                $table->text('lede')->nullable();
                $table->json('body')->nullable();
                $table->string('image_url', 500)->nullable();
                $table->string('template')->nullable();
                $table->string('lead_type')->nullable();
                $table->string('register_kind')->nullable();
                $table->string('product_key')->nullable();
                $table->string('nav_group')->nullable();
                $table->string('nav_label')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('published')->default(true);
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('cms_pages', 'image_url')) {
            Schema::table('cms_pages', function ($table) {
                $table->string('image_url', 500)->nullable();
            });
        }
        $sort = 80;
        foreach (self::appPageCatalog() as $item) {
            $row = CmsPage::query()->where('slug', $item['slug'])->first();
            if ($row) {
                $row->update([
                    'title' => $item['title'],
                    'lede' => $item['lede'],
                    'body' => ['html' => $item['body']],
                    'published' => true,
                    'nav_label' => $item['title'],
                ]);
                continue;
            }
            CmsPage::query()->create([
                'slug' => $item['slug'],
                'title' => $item['title'],
                'lede' => $item['lede'],
                'body' => ['html' => $item['body']],
                'nav_group' => 'legal',
                'nav_label' => $item['title'],
                'sort_order' => $sort++,
                'published' => true,
                'template' => 'app_legal',
            ]);
        }
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
        $slug = (string) $row->slug;

        return [
            'id' => $row->id,
            'slug' => $slug,
            'title' => $row->title,
            'subtitle' => $row->subtitle,
            'group' => $row->service_group,
            'iconKey' => $row->icon_key ?? null,
            'imageUrl' => $this->publicUpload($row->getAttributes()['image_url'] ?? null),
            'badge' => $row->badge ?? null,
            'key' => strtoupper(str_replace('-', '_', $slug)),
            'homeMode' => $this->homeMode($slug),
            'active' => (bool) $row->active,
        ];
    }

    private function homeOffers(): array
    {
        $raw = DB::table('system_settings')->where('key', 'cms_home_offers')->value('value');
        $rows = json_decode((string) $raw, true);

        return is_array($rows) ? array_values($rows) : [];
    }

    private function publicUpload(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || (! str_starts_with($value, '/uploads/') && ! str_starts_with($value, 'http'))) {
            return '';
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return rtrim((string) env('ADMIN_PUBLIC_URL', 'https://admin.karnaride.in'), '/').'/'.ltrim($value, '/');
    }

    private function homeMode(string $slug): string
    {
        return match ($slug) {
            'trip', 'city-ride', 'ride' => 'ride',
            'intercity', 'one-way', 'outstation-one-way' => 'one_way',
            'round-trip', 'round-way', 'outstation-round-trip' => 'round_way',
            'rental', 'cab-rental' => 'rental',
            'pre-book', 'schedule', 'schedule-ride' => 'schedule',
            'bus-train', 'railway', 'railway-transfer' => 'railway',
            'airport', 'airport-transfer' => 'airport',
            'multi-stop', 'multi-stop-ride' => 'multi_stop',
            'parcel', 'parcel-home', 'send-bike', 'send-mini3w', 'send-truck' => 'parcel',
            'travel', 'travel-tours' => 'travel',
            'bulk', 'bulk-booking' => 'bulk',
            'corporate', 'corporate-travel', 'seniors' => 'corporate',
            default => str_replace('-', '_', $slug),
        };
    }

    private function json(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function privacyPolicyBody(): string
    {
        return self::legalBody('privacy-policy');
    }

    public static function legalBody(string $stem): string
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'legal'.DIRECTORY_SEPARATOR.$stem.'.php';
        $text = is_file($path) ? require $path : '';

        return is_string($text) ? $text : '';
    }
}
