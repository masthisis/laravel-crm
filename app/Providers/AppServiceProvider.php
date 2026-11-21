<?php

namespace App\Providers;

use App\Services\Capture\CaptureChannelRegistry;
use App\Services\Capture\Channels\FormEmbedChannel;
use App\Services\Capture\Channels\ManualChannel;
use App\Services\Capture\Channels\WebhookChannel;
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

        // Capture channels — third parties append their own via
        // CaptureChannelRegistry::extend() from a service provider.
        $this->app->afterResolving(CaptureChannelRegistry::class, function (CaptureChannelRegistry $registry): void {
            $registry
                ->extend(WebhookChannel::class)
                ->extend(FormEmbedChannel::class)
                ->extend(ManualChannel::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Persian UI everywhere (spec 01 §1.2): Filament renders RTL
        // automatically for RTL locales.
        app()->setLocale(config('app.locale', 'fa'));

        // super_admin bypasses every permission check
        Gate::before(fn ($user, $ability) => $user->hasRole('super_admin') ? true : null);
    }
}
