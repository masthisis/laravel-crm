<?php

use App\Http\Controllers\Api\EventIngestController;
use App\Http\Controllers\Api\LeadCaptureController;
use App\Http\Controllers\Api\PbxController;
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

/*
|--------------------------------------------------------------------------
| PBX webhooks and desktop/mobile APIs (spec 04 §5.1, §5.6)
|--------------------------------------------------------------------------
| Call events are authenticated with the CRM_PBX_SECRET shared token
| (fixes 11 #3). The desktop/mobile lookups authenticate with a Sanctum
| token instead of query-string credentials (fixes 11 #2).
*/
Route::prefix('pbx')->group(function () {
    Route::get('/service', [PbxController::class, 'service'])
        ->name('api.pbx.service');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/pbx/loginapp', [PbxController::class, 'loginapp'])
        ->name('api.pbx.loginapp');
    Route::get('/pbx/notification', [PbxController::class, 'notification'])
        ->name('api.pbx.notification');
});
