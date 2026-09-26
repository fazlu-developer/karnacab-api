<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\UserPlace;
use App\Services\AppSurfaceService;
use App\Services\BookingService;
use App\Services\CmsCatalogService;
use App\Services\DriverOpsService;
use App\Services\FareService;
use App\Services\LeadIntakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PlatformController extends Controller
{
    public function __construct(
        private readonly CmsCatalogService $cms,
        private readonly FareService $fares,
        private readonly BookingService $bookings,
        private readonly DriverOpsService $drivers,
        private readonly AppSurfaceService $surface,
        private readonly LeadIntakeService $leads,
    ) {}

    public function health()
    {
        $database = ['status' => 'down'];
        try {
            DB::select('select 1');
            $database = ['status' => 'up', 'name' => config('database.connections.mysql.database')];
        } catch (\Throwable $e) {
            $database['message'] = $e->getMessage();
        }

        return [
            'status' => ($database['status'] ?? '') === 'up' ? 'ok' : 'degraded',
            'service' => 'KarnaCab API',
            'runtime' => 'laravel12',
            'version' => '1',
            'prefix' => '/api',
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $database,
                'redis' => ['status' => 'skipped'],
            ],
        ];
    }

    public function cmsSite()
    {
        return $this->cms->site();
    }

    public function appControl()
    {
        $raw = DB::table('system_settings')->where('key', 'cms_site')->value('value');
        $site = json_decode((string) $raw, true);
        if (! is_array($site)) {
            $site = [];
        }
        $admin = rtrim((string) env('ADMIN_PUBLIC_URL', 'https://admin.karnacab.in'), '/');
        $url = function (?string $value) use ($admin): string {
            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }
            if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
                return $value;
            }

            return $admin.'/'.ltrim($value, '/');
        };
        $flag = fn (string $key): bool => filter_var($site[$key] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'customer' => [
                'maintenance' => $flag('customerMaintenance'),
                'forceUpdate' => $flag('customerForceUpdate'),
                'version' => (string) ($site['customerAppVersion'] ?? ''),
                'playStoreUrl' => $url($site['customerPlayStoreUrl'] ?? $site['playStoreUrl'] ?? ''),
                'logoUrl' => $url($site['customerAppLogoUrl'] ?? $site['logoUrl'] ?? ''),
            ],
            'driver' => [
                'maintenance' => $flag('driverMaintenance'),
                'forceUpdate' => $flag('driverForceUpdate'),
                'version' => (string) ($site['driverAppVersion'] ?? ''),
                'playStoreUrl' => $url($site['driverPlayStoreUrl'] ?? ''),
                'logoUrl' => $url($site['driverAppLogoUrl'] ?? ''),
            ],
            'highAlert' => [
                'enabled' => $flag('highAlertEnabled'),
                'message' => (string) ($site['highAlertMessage'] ?? ''),
                'until' => (string) ($site['highAlertUntil'] ?? ''),
            ],
        ];
    }

    public function cmsPage(string $slug)
    {
        return $this->cms->page($slug);
    }

    public function cmsAdminPages()
    {
        return $this->cms->adminList();
    }

    public function cmsPatchSite(Request $request)
    {
        $value = json_encode($request->all());
        DB::table('system_settings')->updateOrInsert(['key' => 'cms_site'], ['value' => $value]);

        return ['site' => $request->all()];
    }

    public function cmsPatchPage(Request $request, string $id)
    {
        $page = CmsPage::query()->findOrFail($id);
        $page->update($request->only(['title', 'eyebrow', 'seo_title', 'seo_description', 'lede', 'body', 'published', 'nav_label', 'sort_order']));

        return $this->cms->page($page->slug);
    }

    public function catalog()
    {
        return $this->cms->catalog();
    }

    public function lead(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|string',
            'name' => 'required|string|min:2',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'district' => 'nullable|string',
            'message' => 'required|string|min:8',
            'payload' => 'nullable|string',
        ]);
        $lead = $this->leads->capture($data);

        return ['id' => (string) $lead->id, 'status' => $lead->status, 'type' => $lead->type];
    }

    public function quoteRide(Request $request)
    {
        return $this->fares->quote($request->all());
    }

    public function quoteOptions(Request $request)
    {
        \App\Support\ServiceArea::assertTrip($request->all());
        $requested = strtoupper((string) $request->input('category', 'BIKE'));
        $options = [];
        foreach (config('karnacab.vehicle_types') as $vehicle) {
            try {
                $quote = $this->fares->quote(array_merge($request->all(), [
                    'product' => $request->input('product', 'LOCAL_CAB'),
                    'category' => $vehicle['key'],
                ]));
            } catch (\Throwable $e) {
                Log::notice('quote.option_skipped', ['category' => $vehicle['key'], 'message' => $e->getMessage()]);
                continue;
            }
            $catalogVehicle = null;
            $image = '';
            try {
                if (Schema::hasTable('catalog_services') && Schema::hasColumn('catalog_services', 'category_key')) {
                    $catalogQuery = DB::table('catalog_services')->where('category_key', $vehicle['key']);
                    if (Schema::hasColumn('catalog_services', 'active')) {
                        $catalogQuery->where('active', 1);
                    }
                    $catalogVehicle = $catalogQuery->first();
                }
                if ($catalogVehicle && Schema::hasColumn('catalog_services', 'image_url')) {
                    $path = (string) ($catalogVehicle->image_url ?? '');
                    if ($path !== '') {
                        $image = str_starts_with($path, 'http')
                            ? $path
                            : rtrim((string) env('ADMIN_PUBLIC_URL', 'https://admin.karnacab.in'), '/').'/'.ltrim($path, '/');
                    }
                }
            } catch (\Throwable $e) {
                Log::notice('quote.catalog_skipped', ['category' => $vehicle['key'], 'message' => $e->getMessage()]);
            }
            $billedKm = (float) ($quote['billedKm'] ?? $request->input('distanceKm') ?? 5);
            $routeMinutes = (int) ($request->input('durationMinutes') ?? 0);
            $durationMinutes = $routeMinutes > 0 ? $routeMinutes : max(1, (int) round($billedKm * 2.3));
            $etaMinutes = match ($vehicle['key']) {
                'BIKE' => 3,
                'AUTO' => 4,
                'E_RICKSHAW' => 6,
                'MINI' => 5,
                'SEDAN' => 8,
                'SUV' => 8,
                'TRAVELLER' => 10,
                default => 7,
            };
            $blurbs = [
                'BIKE' => 'Best for single rider',
                'AUTO' => 'Quick & affordable',
                'E_RICKSHAW' => 'Eco-friendly ride',
                'MINI' => 'Best for small group',
                'SEDAN' => 'Comfortable & spacious',
                'SUV' => 'For family & group',
                'TRAVELLER' => 'Best for large group',
            ];
            $subtitle = trim((string) ($catalogVehicle?->subtitle ?? ''));
            $options[] = array_merge($quote, [
                'label' => $catalogVehicle?->title ?? $vehicle['label'],
                'seats' => $vehicle['seats'],
                'icon' => $vehicle['key'],
                'imageUrl' => $image,
                'blurb' => $subtitle !== '' ? $subtitle : ($blurbs[$vehicle['key']] ?? ''),
                'etaMinutes' => $etaMinutes,
                'durationMinutes' => $durationMinutes,
                'distanceKm' => $billedKm,
            ]);
        }
        usort($options, function ($a, $b) use ($requested) {
            $ak = strtoupper((string) ($a['category'] ?? ''));
            $bk = strtoupper((string) ($b['category'] ?? ''));
            if ($ak === $requested) {
                return -1;
            }
            if ($bk === $requested) {
                return 1;
            }
            return 0;
        });
        $lat = $request->input('pickupLat', $request->input('lat'));
        $lng = $request->input('pickupLng', $request->input('lng'));
        $category = $requested ?: (string) ($options[0]['category'] ?? 'BIKE');
        $markers = [];
        if (is_numeric($lat) && is_numeric($lng)) {
            $markers = app(\App\Services\NearbyDriverService::class)
                ->publicMarkers((float) $lat, (float) $lng, $category)
                ->all();
        }
        $settings = app(\App\Services\RideSettingsService::class)->present();

        return [
            'options' => $options,
            'billedKm' => $request->input('distanceKm'),
            'nearbyDrivers' => $markers,
            'nearbyDriverCount' => count($markers),
            'searchRadiusKm' => $settings['driverSearchRadiusKm'],
            'nearbyDriver' => $markers[0] ?? null,
        ];
    }

    public function rideEngineCatalog()
    {
        return $this->fares->rideCatalog();
    }

    public function rentalPackages(Request $request)
    {
        return $this->fares->rentalPackages($request->integer('districtId') ?: null);
    }

    public function placesAutocomplete(Request $request)
    {
        return $this->fares->autocomplete((string) $request->query('q', ''), $request->query('types'));
    }

    public function placesDetails(Request $request)
    {
        return $this->fares->placeDetails((string) $request->query('placeId', ''));
    }

    public function placesDirections(Request $request)
    {
        return $this->fares->directions(
            (float) $request->query('originLat'),
            (float) $request->query('originLng'),
            (float) $request->query('destLat'),
            (float) $request->query('destLng'),
        );
    }

    public function placesList(Request $request, string $kind)
    {
        $kind = strtoupper($kind) === 'SAVED' ? 'SAVED' : 'RECENT';
        $places = UserPlace::query()
            ->where('user_id', $request->user()->id)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->when($kind === 'RECENT', fn ($q) => $q->limit(8))
            ->get();
        $key = $kind === 'SAVED' ? 'saved' : 'recents';

        return [$key => $places->map(fn ($row) => $this->surface->presentPlace($row))->all()];
    }

    public function placesSave(Request $request, string $kind)
    {
        $data = $request->validate([
            'title' => 'required|string',
            'address' => 'required|string',
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'subtitle' => 'nullable|string',
        ]);
        $kind = strtoupper($kind) === 'SAVED' ? 'SAVED' : 'RECENT';
        $title = $data['title'];
        $existing = null;
        if ($kind === 'SAVED' && in_array($title, ['Home', 'Work'], true)) {
            $existing = UserPlace::query()
                ->where('user_id', $request->user()->id)
                ->where('kind', 'SAVED')
                ->where('title', $title)
                ->first();
        }
        $payload = [
            'user_id' => $request->user()->id,
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $data['subtitle'] ?? null,
            'address' => $data['address'],
            'lat' => $data['lat'],
            'lng' => $data['lng'],
        ];
        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }
        $payload['created_at'] = now();
        if ($kind === 'RECENT') {
            UserPlace::query()
                ->where('user_id', $request->user()->id)
                ->where('kind', 'RECENT')
                ->where(function ($query) use ($title, $data) {
                    $query->where('title', $title)
                        ->orWhere(function ($inner) use ($data) {
                            $inner->whereRaw('ROUND(lat, 4) = ?', [round((float) $data['lat'], 4)])
                                ->whereRaw('ROUND(lng, 4) = ?', [round((float) $data['lng'], 4)]);
                        });
                })
                ->delete();
        }
        $place = UserPlace::query()->create($payload);
        if ($kind === 'RECENT') {
            $keepIds = UserPlace::query()
                ->where('user_id', $request->user()->id)
                ->where('kind', 'RECENT')
                ->orderByDesc('id')
                ->limit(8)
                ->pluck('id');
            UserPlace::query()
                ->where('user_id', $request->user()->id)
                ->where('kind', 'RECENT')
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        return $place;
    }

    public function placesDelete(Request $request, string $id)
    {
        UserPlace::query()->where('user_id', $request->user()->id)->where('id', $id)->delete();

        return ['ok' => true];
    }

    public function bookingsCreate(Request $request)
    {
        return $this->bookings->create($request->user(), $request->all());
    }

    public function bookingsList(Request $request)
    {
        return response()->json($this->bookings->list($request->user()));
    }

    public function bookingsOne(Request $request, string $id)
    {
        return $this->bookings->one($request->user(), $id);
    }

    public function bookingsLive(Request $request, string $id)
    {
        return $this->bookings->live($request->user(), $id);
    }

    public function bookingsAccept(Request $request, string $id)
    {
        return $this->bookings->accept($request->user(), $id);
    }

    public function bookingsReject(Request $request, string $id)
    {
        return $this->bookings->reject($request->user(), $id);
    }

    public function bookingsCancel(Request $request, string $id)
    {
        return $this->bookings->cancel($request->user(), $id, $request->all());
    }

    public function bookingsVerifyOtp(Request $request, string $id)
    {
        $data = $request->validate(['otp' => 'required|string']);

        return $this->bookings->verifyOtp($request->user(), $id, $data['otp']);
    }

    public function bookingsStart(Request $request, string $id)
    {
        $data = $request->validate(['otp' => 'required|string']);

        return $this->bookings->verifyOtp($request->user(), $id, $data['otp']);
    }

    public function bookingsComplete(Request $request, string $id)
    {
        return $this->bookings->complete($request->user(), $id);
    }

    public function bookingsLifecycle(Request $request, string $id)
    {
        return $this->bookings->lifecycle(
            $request->user(),
            $id,
            (string) ($request->input('action') ?? $request->input('status', 'CANCELLED')),
            $request->input('otp') !== null ? (string) $request->input('otp') : null,
            $request->all(),
        );
    }

    public function bookingsReschedule(Request $request, string $id)
    {
        $booking = \App\Models\Booking::query()->findOrFail($id);
        $booking->update(['scheduled_at' => $request->input('scheduledAt')]);

        return $this->bookings->present($booking->fresh());
    }

    public function bookingsRate(Request $request, string $id)
    {
        $this->bookings->rate($request->user(), $id, (int) $request->input('stars', 5), (string) ($request->input('comment') ?? ''));

        return ['ok' => true];
    }

    public function driversMe(Request $request)
    {
        return $this->drivers->me($request->user());
    }

    public function driversList(Request $request)
    {
        return $this->drivers->list($request->user());
    }

    public function driversOnline(Request $request)
    {
        return $this->drivers->setOnline($request->user(), (bool) $request->input('online', true));
    }

    public function driversLocation(Request $request)
    {
        if ($request->isMethod('get')) {
            $user = $request->user();

            return [
                'lat' => $user->last_lat,
                'lng' => $user->last_lng,
                'heading' => $user->last_heading ?? null,
                'recordedAt' => optional($user->location_updated_at)?->toIso8601String(),
            ];
        }
        $data = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'heading' => 'nullable|numeric',
            'bookingId' => 'nullable|string',
        ]);

        return $this->drivers->pingLocation(
            $request->user(),
            (float) $data['lat'],
            (float) $data['lng'],
            isset($data['heading']) ? (float) $data['heading'] : null,
            $data['bookingId'] ?? null,
        );
    }

    public function driverSupport(Request $request)
    {
        $payload = $this->surface->supportTickets($request->user(), $request);
        if ($request->isMethod('post')) {
            return $payload;
        }

        return [
            'tickets' => $payload['tickets'] ?? [],
            'faqs' => [
                ['q' => 'How do I go online?', 'a' => 'Finish KYC, keep location on, then tap Go online.'],
                ['q' => 'How much wallet do I need?', 'a' => 'Admin sets a percent of the fare. At 10%, a ₹1,000 wallet can accept a ₹10,000 booking.'],
                ['q' => 'Where are my documents reviewed?', 'a' => 'Open Support and send a ticket. The admin team sees the same ticket.'],
            ],
            'tel' => 'tel:08041234500',
        ];
    }

    public function driversNearby(Request $request)
    {
        $data = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'category' => 'nullable|string',
        ]);
        $category = strtoupper((string) ($data['category'] ?? 'BIKE'));
        $settings = app(\App\Services\RideSettingsService::class)->present();
        $markers = app(\App\Services\NearbyDriverService::class)
            ->publicMarkers((float) $data['lat'], (float) $data['lng'], $category)
            ->all();

        return [
            'radiusKm' => $settings['driverSearchRadiusKm'],
            'category' => $category,
            'count' => count($markers),
            'drivers' => $markers,
        ];
    }

    public function rideSettings()
    {
        return app(\App\Services\RideSettingsService::class)->present();
    }

    public function driversOffers(Request $request)
    {
        return $this->drivers->offers($request->user());
    }

    public function driversDashboard(Request $request)
    {
        return $this->drivers->dashboard($request->user());
    }

    public function driversTrips(Request $request)
    {
        return $this->drivers->trips($request->user());
    }

    public function driversDocuments(Request $request)
    {
        return $this->drivers->documents($request->user());
    }

    public function driversRatings(Request $request)
    {
        return $this->drivers->ratings($request->user());
    }

    public function driversEarnings(Request $request)
    {
        return $this->drivers->earnings($request->user());
    }

    public function vehicles(Request $request)
    {
        return $this->drivers->vehicles($request->user());
    }

    public function kycCatalog()
    {
        return $this->drivers->kycCatalog();
    }

    public function kycMe(Request $request)
    {
        return $this->drivers->kycSnapshot($request->user());
    }

    public function kycProfile(Request $request)
    {
        return $this->drivers->patchProfile($request->user(), $request->all());
    }

    public function kycUpload(\Illuminate\Http\Request $request)
    {
        return app(\App\Services\KycDocumentService::class)->upload(
            $request->user(),
            $request->all(),
            $request->file('file'),
        );
    }

    public function kycDelete(Request $request, string $id)
    {
        return app(\App\Services\KycDocumentService::class)->delete($request->user(), $id);
    }

    public function kycSubmit(Request $request)
    {
        return $this->drivers->submitKyc($request->user());
    }

    public function kycReview(Request $request, string $driverId)
    {
        return $this->drivers->reviewKyc($driverId, (string) $request->input('status', 'approved'), $request->input('reason'));
    }

    public function table(Request $request, string $table, ?string $id = null)
    {
        $allowed = [
            'wallets', 'payments', 'invoices', 'parcel_shipments', 'travel_packages', 'travel_bookings',
            'bulk_bookings', 'corporate_accounts', 'ad_campaigns', 'support_tickets', 'support_faqs',
            'safety_incidents', 'franchises', 'fleet_owners', 'vehicles', 'fare_rules', 'commission_rules',
            'user_notifications', 'coupons',
        ];
        if (! in_array($table, $allowed, true)) {
            return response()->json(['error' => 'Unknown module', 'module' => $table], 404);
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
            return response()->json(['error' => 'Not ported yet', 'module' => $table], 501);
        }
        if ($id) {
            $row = DB::table($table)->where('id', $id)->first();
            abort_unless($row, 404);

            return (array) $row;
        }
        if ($request->isMethod('post')) {
            $payload = $request->except(['_token']);
            if (! isset($payload['created_at']) && \Illuminate\Support\Facades\Schema::hasColumn($table, 'created_at')) {
                $payload['created_at'] = now();
            }
            $newId = DB::table($table)->insertGetId($payload);

            return ['id' => (string) $newId, 'ok' => true];
        }
        if ($request->isMethod('patch') || $request->isMethod('put')) {
            return ['error' => 'Use id in path'];
        }

        return ['rows' => DB::table($table)->orderByDesc('id')->limit(100)->get()];
    }

    public function walletMe(Request $request)
    {
        return $this->surface->walletMe($request->user());
    }

    public function walletTopup(Request $request)
    {
        $rupees = (float) ($request->input('amountRupees') ?? $request->input('amount') ?? 0);

        return app(\App\Services\PayUService::class)->startTopup($request->user(), $rupees);
    }

    public function payuCheckout(string $txnid)
    {
        return response(app(\App\Services\PayUService::class)->checkoutPage($txnid), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function payuHash(Request $request)
    {
        return app(\App\Services\PayUService::class)->sdkHash($request);
    }

    public function payuWebhook(Request $request)
    {
        $result = app(\App\Services\PayUService::class)->handleWebhook($request);
        if ($request->expectsJson()) {
            return $result;
        }
        $status = e((string) ($result['status'] ?? 'received'));
        $txn = e((string) ($result['txnid'] ?? ''));
        $ok = in_array($result['status'] ?? '', ['captured', 'success'], true);
        $headline = $ok ? 'Payment received' : 'Payment not completed';
        $copy = $ok
            ? 'Wallet balance will update in the KarnaCab app.'
            : 'You can close this window and try again from the app.';

        return response(<<<HTML
<!DOCTYPE html>
<html><body style="margin:0;background:#f4f1ea;font-family:Segoe UI,Arial,sans-serif;color:#10231c">
  <main id="karnacab-payu" data-status="{$status}" style="max-width:420px;margin:48px auto;background:#fff;border-radius:18px;padding:28px">
    <p style="letter-spacing:.12em;text-transform:uppercase;color:#5c564c;font-size:12px">KarnaCab PayU</p>
    <h1 style="margin:8px 0">{$headline}</h1>
    <p>{$copy}</p>
    <p style="color:#5c564c">Reference {$txn}</p>
  </main>
</body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function notificationRead(Request $request, string $id)
    {
        return $this->surface->markNotificationRead($request->user(), $id);
    }

    public function experience(Request $request)
    {
        return $this->surface->experienceOverview($request->user());
    }

    public function experienceCatalog()
    {
        return $this->surface->experienceCatalog();
    }

    public function couponPreview(Request $request)
    {
        return $this->surface->previewCoupon($request->user(), $request->all());
    }

    public function family(Request $request)
    {
        return $this->surface->family($request->user(), $request);
    }

    public function invoices(Request $request)
    {
        return $this->surface->invoices($request->user());
    }

    public function invoiceOne(Request $request, string $id)
    {
        return $this->surface->invoiceOne($request->user(), $id);
    }

    public function supportFaqs(Request $request)
    {
        return $this->surface->supportFaqs($request->query('audience', 'customer'));
    }

    public function supportTickets(Request $request)
    {
        return $this->surface->supportTickets($request->user(), $request);
    }

    public function supportTicket(Request $request, string $id)
    {
        return $this->surface->supportTicket($request->user(), $id);
    }

    public function supportTicketMessage(Request $request, string $id)
    {
        return $this->surface->supportTicketMessage($request->user(), $id, (string) $request->input('body', ''));
    }

    public function adsServe(Request $request)
    {
        return $this->surface->adsServe($request->query('placement'));
    }

    public function adsNoop()
    {
        return ['ok' => true];
    }

    public function safetyCatalog()
    {
        return $this->surface->safetyCatalog();
    }

    public function safetyMe(Request $request)
    {
        return $this->surface->safetyMe($request->user());
    }

    public function safetyIncidents(Request $request)
    {
        return $this->surface->safetyIncidents($request->user());
    }

    public function safetySos(Request $request)
    {
        return ['ok' => true, 'helpline' => '112', 'lat' => $request->input('lat'), 'lng' => $request->input('lng')];
    }

    public function parcelCatalog()
    {
        return $this->surface->parcelCatalog();
    }

    public function parcelsList(Request $request)
    {
        return $this->surface->parcelsList($request->user());
    }

    public function parcelsCreate(Request $request)
    {
        return $this->surface->parcelCreate($request->user(), $request->all());
    }

    public function parcelsQuote(Request $request)
    {
        return $this->surface->parcelQuote($request->all());
    }

    public function parcelsQuoteOptions(Request $request)
    {
        return $this->surface->parcelQuoteOptions($request->all());
    }

    public function parcelsOne(string $id)
    {
        return $this->surface->parcelOne($id);
    }

    public function parcelsPay(Request $request, string $id)
    {
        return $this->surface->parcelPay($request->user(), $id, $request->all());
    }

    public function parcelsAccept(Request $request, string $id)
    {
        return $this->surface->parcelAccept($request->user(), $id);
    }

    public function parcelsReject(string $id)
    {
        return $this->surface->parcelReject($id);
    }

    public function parcelsLifecycle(Request $request, string $id)
    {
        return $this->surface->parcelLifecycle($id, (string) ($request->input('action') ?? $request->input('status', 'cancel')));
    }

    public function travelCatalog()
    {
        return $this->surface->travelCatalog();
    }

    public function travelPackages(Request $request, ?string $id = null)
    {
        return $this->surface->travelPackages($request, $id);
    }

    public function travelBook(Request $request)
    {
        return $this->surface->travelBook($request->user(), $request->all());
    }

    public function travelPay(Request $request, string $id)
    {
        return $this->surface->travelPay($request->user(), $id, $request->all());
    }

    public function bulkCatalog()
    {
        return $this->surface->bulkCatalog();
    }

    public function bulkQuote(Request $request)
    {
        return $this->surface->bulkQuote($request->all());
    }

    public function bulkCreate(Request $request)
    {
        return $this->surface->bulkCreate($request->user(), $request->all());
    }

    public function corporateCatalog()
    {
        return $this->surface->corporateCatalog();
    }

    public function corporateQuote(Request $request)
    {
        return $this->surface->corporateQuote($request->all());
    }

    public function corporateBook(Request $request)
    {
        return $this->surface->corporateBook($request->user(), $request->all());
    }

    public function session(Request $request)
    {
        return [
            'user' => app(\App\Services\AuthService::class)->present($request->user()),
            'runtime' => 'laravel12',
        ];
    }
}
