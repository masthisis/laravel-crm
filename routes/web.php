<?php

use App\Http\Controllers\Agent\RegisterCustomerController;
use App\Http\Controllers\Api\LeadCaptureController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('filament.admin.auth.login'));

// Embeddable lead-capture form for external sites (blog articles, landing pages...)
Route::get('/embed/leads/{integration}', [LeadCaptureController::class, 'show'])
    ->name('leads.embed-form');

/*
|--------------------------------------------------------------------------
| Agent workspace routes (spec 04)
|--------------------------------------------------------------------------
| The unknown-number register page (04 §3.4) — a plain route inside the
| agent panel auth group; everything else lives in the Filament panels.
*/
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/agent/register/{phone}', [RegisterCustomerController::class, 'show'])
        ->name('agent.register.show');
    Route::post('/agent/register/{phone}', [RegisterCustomerController::class, 'store'])
        ->name('agent.register.store');
});
