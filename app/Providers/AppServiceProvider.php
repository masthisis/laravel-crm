<?php

namespace App\Providers;

use App\Services\CommissionService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped singleton: one instance per request/job, so the
        // commission rule set is loaded once and memoized for every
        // estimateForLead() call (tables render N rows with one query).
        $this->app->scoped(CommissionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super_admin bypasses every permission check
        Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
    }
}
