<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:expire-searching', function () {
    $count = app(\App\Services\BookingService::class)->expireSearching();
    $this->info("Expired {$count} searching booking(s).");
})->purpose('Expire ride requests that nobody accepted');

Artisan::command('kyc:expire-documents', function () {
    $count = app(\App\Services\KycDocumentService::class)->expireDue();
    $this->info("Expired {$count} driver document(s).");
})->purpose('Expire driver KYC documents and notify admins');

Schedule::command('bookings:expire-searching')->everyMinute();
Schedule::command('kyc:expire-documents')->dailyAt('01:15');
