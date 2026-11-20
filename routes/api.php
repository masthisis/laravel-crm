<?php

use App\Http\Controllers\Api\LeadCaptureController;
use Illuminate\Support\Facades\Route;

Route::post('/leads', [LeadCaptureController::class, 'store'])
    ->middleware('throttle:'.config('crm.capture.throttle', 30).',1')
    ->name('api.leads.capture');
