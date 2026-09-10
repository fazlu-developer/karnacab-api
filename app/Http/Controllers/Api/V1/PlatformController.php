<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use App\Models\Lead;
use App\Models\UserPlace;
use App\Services\AppSurfaceService;
use App\Services\BookingService;
use App\Services\CmsCatalogService;
use App\Services\DriverOpsService;
use App\Services\FareService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    public function __construct(
        private readonly CmsCatalogService $cms,
        private readonly FareService $fares,
        private readonly BookingService $bookings,
        private readonly DriverOpsService $drivers,
        private readonly AppSurfaceService $surface,
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
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'district' => 'nullable|string',
            'message' => 'required|string|min:8',
            'payload' => 'nullable|string',
        ]);
        $lead = Lead::query()->create(array_merge($data, ['status' => 'NEW']));

        return ['id' => (string) $lead->id, 'status' => $lead->status, 'type' => $lead->type];
    }

    public function quoteRide(Request $request)
    {
        return $this->fares->quote($request->all());
    }

    public function quoteOptions(Request $request)
    {
        $options = [];
        foreach (config('karnacab.vehicle_types') as $vehicle) {
            try {
                $quote = $this->fares->quote(array_merge($request->all(), ['category' => $vehicle['key']]));
                $options[] = array_merge($quote, [
                    'label' => $vehicle['label'],
                    'seats' => $vehicle['seats'],
                ]);
            } catch (\Throwable) {
                continue;
            }
        }

        return ['options' => $options, 'billedKm' => $request->input('distanceKm')];
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
        $places = UserPlace::query()->where('user_id', $request->user()->id)->where('kind', $kind)->orderByDesc('id')->get();
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
        $place = UserPlace::query()->create([
            'user_id' => $request->user()->id,
            'kind' => strtoupper($kind) === 'SAVED' ? 'SAVED' : 'RECENT',
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'address' => $data['address'],
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'created_at' => now(),
        ]);

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
        $booking = $this->bookings->one($request->user(), $id);

        return ['booking' => $booking, 'driver' => $booking['driverId']];
    }

    public function bookingsAccept(Request $request, string $id)
    {
        return $this->bookings->accept($request->user(), $id);
    }

    public function bookingsReject(Request $request, string $id)
    {
        return $this->bookings->reject($request->user(), $id);
    }

    public function bookingsLifecycle(Request $request, string $id)
    {
        return $this->bookings->lifecycle(
            $request->user(),
            $id,
            (string) ($request->input('action') ?? $request->input('status', 'CANCELLED')),
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
        DB::table('booking_ratings')->updateOrInsert(
            ['booking_id' => $id, 'from_role' => $request->user()->role],
            ['stars' => (int) $request->input('stars', 5), 'comment' => $request->input('comment'), 'created_at' => now()],
        );

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
        return $this->drivers->pingLocation($request->user(), (float) $request->input('lat'), (float) $request->input('lng'));
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

    public function supportFaqs(Request $request)
    {
        return $this->surface->supportFaqs($request->query('audience', 'customer'));
    }

    public function supportTickets(Request $request)
    {
        return $this->surface->supportTickets($request->user(), $request);
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

    public function parcelsOne(string $id)
    {
        return $this->surface->parcelOne($id);
    }

    public function parcelsPay(Request $request, string $id)
    {
        return $this->surface->parcelPay($id, $request->all());
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
        return $this->surface->travelPay($id, $request->all());
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

    public function session(Request $request)
    {
        return [
            'user' => app(\App\Services\AuthService::class)->present($request->user()),
            'runtime' => 'laravel12',
        ];
    }
}
