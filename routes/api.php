<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PlatformController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', [PlatformController::class, 'health']);
Route::get('/v1/cms/site', [PlatformController::class, 'cmsSite']);
Route::get('/v1/cms/pages/{slug}', [PlatformController::class, 'cmsPage']);
Route::get('/v1/catalog', [PlatformController::class, 'catalog']);
Route::post('/v1/leads', [PlatformController::class, 'lead']);
Route::post('/v1/quotes/ride', [PlatformController::class, 'quoteRide']);
Route::post('/v1/quotes/options', [PlatformController::class, 'quoteOptions']);
Route::get('/v1/ride-engine/catalog', [PlatformController::class, 'rideEngineCatalog']);
Route::get('/v1/ride-engine/rental-packages', [PlatformController::class, 'rentalPackages']);
Route::get('/v1/places/autocomplete', [PlatformController::class, 'placesAutocomplete']);
Route::get('/v1/places/details', [PlatformController::class, 'placesDetails']);
Route::get('/v1/places/directions', [PlatformController::class, 'placesDirections']);
Route::get('/v1/kyc/catalog', [PlatformController::class, 'kycCatalog']);
Route::get('/v1/travel/catalog', [PlatformController::class, 'travelCatalog']);
Route::get('/v1/travel/packages', [PlatformController::class, 'travelPackages']);
Route::get('/v1/travel/packages/{id}', [PlatformController::class, 'travelPackages']);
Route::get('/v1/parcels/catalog', [PlatformController::class, 'parcelCatalog']);
Route::get('/v1/bulk/catalog', [PlatformController::class, 'bulkCatalog']);
Route::get('/v1/ads/catalog', fn () => ['ok' => true, 'module' => 'ads']);
Route::get('/v1/support/catalog', [PlatformController::class, 'supportFaqs']);
Route::get('/v1/support/faqs', [PlatformController::class, 'supportFaqs']);
Route::get('/v1/safety/catalog', [PlatformController::class, 'safetyCatalog']);
Route::get('/v1/fleet/catalog', fn () => ['ok' => true, 'module' => 'fleet']);
Route::get('/v1/franchise/catalog', fn () => ['ok' => true, 'module' => 'franchise']);
Route::get('/v1/experience/catalog', [PlatformController::class, 'experienceCatalog']);
Route::get('/v1/notifications/catalog', fn () => ['ok' => true, 'module' => 'notifications']);
Route::get('/v1/payments/catalog', fn () => ['gateway' => env('PAYMENT_GATEWAY', 'demo')]);
Route::post('/v1/payments/webhooks/{provider}', fn (string $provider) => ['ok' => true, 'provider' => $provider]);

Route::post('/v1/auth/register', [AuthController::class, 'register']);
Route::post('/v1/auth/register-advertiser', [AuthController::class, 'registerAdvertiser']);
Route::post('/v1/auth/register-driver', [AuthController::class, 'registerDriver']);
Route::post('/v1/auth/login', [AuthController::class, 'login']);
Route::post('/v1/auth/driver/login', [AuthController::class, 'driverLogin']);
Route::post('/v1/auth/advertiser/login', [AuthController::class, 'advertiserLogin']);
Route::post('/v1/auth/operator/login', [AuthController::class, 'operatorLogin']);
Route::post('/v1/auth/otp/request', [AuthController::class, 'requestOtp']);
Route::post('/v1/auth/otp/verify', [AuthController::class, 'verifyOtp']);
Route::post('/v1/auth/driver/otp/verify', [AuthController::class, 'verifyDriverOtp']);

Route::middleware('jwt')->group(function () {
    Route::get('/v1/auth/me', [AuthController::class, 'me']);
    Route::patch('/v1/auth/profile', [AuthController::class, 'profile']);
    Route::patch('/v1/auth/location', [AuthController::class, 'location']);
    Route::get('/v1/platform/session', [PlatformController::class, 'session']);
    Route::get('/v1/platform/roles', fn () => ['roles' => ['CUSTOMER', 'DRIVER', 'ADMIN', 'SUPER_ADMIN', 'FLEET_OWNER', 'DISTRICT_HEAD', 'STATE_HEAD', 'FRANCHISE', 'CORPORATE', 'ADVERTISER']]);

    Route::get('/v1/cms/admin/pages', [PlatformController::class, 'cmsAdminPages']);
    Route::patch('/v1/cms/admin/site', [PlatformController::class, 'cmsPatchSite']);
    Route::patch('/v1/cms/admin/pages/{id}', [PlatformController::class, 'cmsPatchPage']);

    Route::get('/v1/places/recent', function (Request $request) {
        return app(PlatformController::class)->placesList($request, 'RECENT');
    });
    Route::get('/v1/places/saved', function (Request $request) {
        return app(PlatformController::class)->placesList($request, 'SAVED');
    });
    Route::post('/v1/places/saved', function (Request $request) {
        return app(PlatformController::class)->placesSave($request, 'SAVED');
    });
    Route::post('/v1/places/recent', function (Request $request) {
        return app(PlatformController::class)->placesSave($request, 'RECENT');
    });
    Route::delete('/v1/places/{id}', [PlatformController::class, 'placesDelete']);

    Route::post('/v1/bookings', [PlatformController::class, 'bookingsCreate']);
    Route::get('/v1/bookings', [PlatformController::class, 'bookingsList']);
    Route::get('/v1/bookings/{id}/live', [PlatformController::class, 'bookingsLive']);
    Route::get('/v1/bookings/{id}', [PlatformController::class, 'bookingsOne']);
    Route::post('/v1/bookings/{id}/accept', [PlatformController::class, 'bookingsAccept']);
    Route::post('/v1/bookings/{id}/reject', [PlatformController::class, 'bookingsReject']);
    Route::post('/v1/bookings/{id}/reschedule', [PlatformController::class, 'bookingsReschedule']);
    Route::post('/v1/bookings/{id}/rate', [PlatformController::class, 'bookingsRate']);
    Route::post('/v1/bookings/{id}/lifecycle', [PlatformController::class, 'bookingsLifecycle']);

    Route::get('/v1/drivers', [PlatformController::class, 'driversList']);
    Route::get('/v1/drivers/me', [PlatformController::class, 'driversDashboard']);
    Route::get('/v1/drivers/me/dashboard', [PlatformController::class, 'driversDashboard']);
    Route::get('/v1/drivers/me/trips', [PlatformController::class, 'driversTrips']);
    Route::get('/v1/drivers/me/documents', [PlatformController::class, 'driversDocuments']);
    Route::get('/v1/drivers/me/incentives', fn () => ['incentives' => []]);
    Route::get('/v1/drivers/me/ratings', fn () => ['ratings' => []]);
    Route::get('/v1/drivers/me/support', fn () => ['tickets' => []]);
    Route::post('/v1/drivers/me/support/tickets', fn () => ['ok' => true, 'module' => 'support']);
    Route::get('/v1/drivers/me/sos', fn () => ['ok' => true]);
    Route::post('/v1/drivers/me/sos', fn () => ['ok' => true]);
    Route::get('/v1/drivers/me/earnings', [PlatformController::class, 'driversEarnings']);
    Route::post('/v1/drivers/me/wallet/withdraw', fn () => ['ok' => true, 'module' => 'wallets']);
    Route::patch('/v1/drivers/me/online', [PlatformController::class, 'driversOnline']);
    Route::patch('/v1/drivers/me/duty', [PlatformController::class, 'driversOnline']);
    Route::post('/v1/drivers/me/location', [PlatformController::class, 'driversLocation']);
    Route::get('/v1/drivers/me/location', [PlatformController::class, 'driversLocation']);
    Route::get('/v1/drivers/offers', [PlatformController::class, 'driversOffers']);
    Route::get('/v1/drivers/{id}', [PlatformController::class, 'driversMe']);

    Route::get('/v1/drivers/me/kyc', [PlatformController::class, 'kycMe']);
    Route::patch('/v1/drivers/me/profile', [PlatformController::class, 'kycProfile']);
    Route::patch('/v1/drivers/me/vehicle', [PlatformController::class, 'kycProfile']);
    Route::patch('/v1/drivers/me/bank', [PlatformController::class, 'kycProfile']);
    Route::post('/v1/drivers/me/documents', fn () => ['ok' => true, 'module' => 'kyc']);
    Route::post('/v1/drivers/me/kyc/submit', [PlatformController::class, 'kycSubmit']);
    Route::get('/v1/ops/kyc', [PlatformController::class, 'driversList']);
    Route::patch('/v1/ops/kyc/{driverId}', [PlatformController::class, 'kycReview']);

    Route::get('/v1/vehicles', [PlatformController::class, 'vehicles']);
    Route::get('/v1/vehicles/me', [PlatformController::class, 'vehicles']);
    Route::get('/v1/vehicles/live', [PlatformController::class, 'vehicles']);
    Route::get('/v1/vehicles/{id}', [PlatformController::class, 'vehicles']);

    Route::get('/v1/wallets/me', [PlatformController::class, 'walletMe']);
    Route::get('/v1/wallets/commission-policy', fn () => ['percent' => 0]);
    Route::get('/v1/wallets/{id}', [PlatformController::class, 'walletMe']);

    Route::get('/v1/experience', [PlatformController::class, 'experience']);
    Route::get('/v1/experience/dashboard', [PlatformController::class, 'experience']);
    Route::post('/v1/experience/coupons/preview', [PlatformController::class, 'couponPreview']);
    Route::get('/v1/experience/family', [PlatformController::class, 'family']);
    Route::post('/v1/experience/family', [PlatformController::class, 'family']);
    Route::get('/v1/payments/invoices', [PlatformController::class, 'invoices']);
    Route::get('/v1/ads/serve', [PlatformController::class, 'adsServe']);
    Route::post('/v1/ads/campaigns/{id}/impression', [PlatformController::class, 'adsNoop']);
    Route::post('/v1/ads/campaigns/{id}/click', [PlatformController::class, 'adsNoop']);
    Route::get('/v1/support/tickets', [PlatformController::class, 'supportTickets']);
    Route::post('/v1/support/tickets', [PlatformController::class, 'supportTickets']);
    Route::get('/v1/safety/me', [PlatformController::class, 'safetyMe']);
    Route::get('/v1/safety/sos', [PlatformController::class, 'safetySos']);
    Route::post('/v1/safety/sos', [PlatformController::class, 'safetySos']);
    Route::post('/v1/safety/share', [PlatformController::class, 'safetySos']);
    Route::post('/v1/safety/tickets', [PlatformController::class, 'supportTickets']);
    Route::get('/v1/safety/incidents', fn () => ['incidents' => []]);
    Route::get('/v1/safety/bookings/{bookingId}/verify', fn () => ['ok' => true]);
    Route::patch('/v1/safety/emergency-contact', [AuthController::class, 'profile']);
    Route::post('/v1/parcels/quote', [PlatformController::class, 'parcelsQuote']);
    Route::get('/v1/parcels', [PlatformController::class, 'parcelsList']);
    Route::post('/v1/parcels', [PlatformController::class, 'parcelsCreate']);
    Route::get('/v1/parcels/{id}', [PlatformController::class, 'parcelsOne']);
    Route::post('/v1/parcels/{id}/pay', [PlatformController::class, 'parcelsPay']);
    Route::post('/v1/parcels/{id}/accept', [PlatformController::class, 'parcelsAccept']);
    Route::post('/v1/parcels/{id}/reject', [PlatformController::class, 'parcelsReject']);
    Route::post('/v1/parcels/{id}/lifecycle', [PlatformController::class, 'parcelsLifecycle']);
    Route::post('/v1/travel/bookings', [PlatformController::class, 'travelBook']);
    Route::post('/v1/travel/bookings/{id}/pay', [PlatformController::class, 'travelPay']);
    Route::post('/v1/bulk/quote', [PlatformController::class, 'bulkQuote']);
    Route::post('/v1/bulk', [PlatformController::class, 'bulkCreate']);

    $modules = [
        'corporate' => 'corporate_accounts',
        'fleet' => 'fleet_owners',
        'franchise' => 'franchises',
        'notifications' => 'user_notifications',
        'ops' => 'platform_audit_events',
        'state' => 'districts',
        'district' => 'districts',
    ];
    foreach ($modules as $prefix => $table) {
        Route::get('/v1/'.$prefix, fn (Request $request) => app(PlatformController::class)->table($request, $table));
        Route::post('/v1/'.$prefix, fn (Request $request) => app(PlatformController::class)->table($request, $table));
        Route::get('/v1/'.$prefix.'/{id}', fn (Request $request, string $id) => app(PlatformController::class)->table($request, $table, $id));
        Route::patch('/v1/'.$prefix.'/{id}', function (Request $request, string $id) use ($table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                return response()->json(['error' => 'Not ported yet', 'module' => $table], 501);
            }
            \Illuminate\Support\Facades\DB::table($table)->where('id', $id)->update($request->except(['_token', 'id']));

            return ['ok' => true, 'id' => $id];
        });
    }
});
