<?php

use App\Http\Controllers\Api\EventIngestController;
use App\Http\Controllers\Api\LeadCaptureController;
use Illuminate\Support\Facades\Route;

Route::post('/leads', [LeadCaptureController::class, 'store'])
    ->middleware('throttle:'.config('crm.capture.throttle', 30).',1')
    ->name('api.leads.capture');

/*
|--------------------------------------------------------------------------
| Event intake (spec 03): HMAC-signed lead/account/invoice/call events
|--------------------------------------------------------------------------
*/
Route::post('/events', [EventIngestController::class, 'store'])
    ->name('api.events.ingest');
