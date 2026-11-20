<?php

use App\Http\Controllers\Api\LeadCaptureController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('filament.admin.auth.login'));

// Embeddable lead-capture form for external sites (blog articles, landing pages...)
Route::get('/embed/leads/{integration}', [LeadCaptureController::class, 'show'])
    ->name('leads.embed-form');
