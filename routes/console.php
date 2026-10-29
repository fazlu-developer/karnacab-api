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

Artisan::command('fleet:settle-weeks', function () {
    $count = app(\App\Services\OperatorFleetService::class)->settleAllDueWeeks();
    $this->info("Posted {$count} fleet week settlement(s).");
})->purpose('Credit fleet owners the day after each completed week');

Artisan::command('bookings:remind-upcoming', function () {
    $count = app(\App\Services\BookingService::class)->remindUpcoming();
    $this->info("Sent {$count} upcoming ride reminder(s).");
})->purpose('Push a reminder 3 hours before a scheduled ride');

Artisan::command('corporate:remind', function () {
    $count = app(\App\Services\Corporate\CorporatePlanService::class)->remindDue();
    $count += app(\App\Services\Bulk\BulkBookingService::class)->remindDue();
    $count += app(\App\Services\Tour\TourBookingService::class)->remindDue();
    $this->info("Sent {$count} corporate, bulk, and tour reminder(s).");
})->purpose('Remind corporate daily pickups and mark expired plans');

Schedule::command('bookings:expire-searching')->everyMinute();
Schedule::command('bookings:remind-upcoming')->everyFiveMinutes();
Schedule::command('corporate:remind')->everyFiveMinutes();
Schedule::command('kyc:expire-documents')->dailyAt('01:15');
Schedule::command('fleet:settle-weeks')->dailyAt('00:20');
