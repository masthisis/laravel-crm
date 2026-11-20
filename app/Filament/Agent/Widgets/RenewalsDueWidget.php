<?php

namespace App\Filament\Agent\Widgets;

use App\Models\Customer;
use Filament\Widgets\Widget;

/**
 * Subscriptions belonging to MY customers renewing within the renewal
 * window — the proactive renewal call list.
 */
class RenewalsDueWidget extends Widget
{
    protected static string $view = 'filament.agent.widgets.renewals-due';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $window = now()->addDays((int) config('crm.subscriptions.renewal_window', 30));

        $renewals = Customer::query()
            ->whereHas('leads', fn ($query) => $query->where('assigned_agent_id', auth()->id()))
            ->with('subscriptions.product')
            ->get()
            ->map(fn (Customer $customer) => [
                'customer' => $customer->name,
                'customer_id' => $customer->id,
                'subscriptions' => $customer->subscriptions
                    ->filter(fn ($s) => $s->getAttribute('ends_at') !== null && $s->getAttribute('ends_at')->lessThanOrEqualTo($window))
                    ->map(fn ($s) => $s->product->name.' — renews '.$s->getAttribute('ends_at')?->diffForHumans())->values(),
            ])
            ->filter(fn (array $row) => $row['subscriptions']->isNotEmpty());

        return ['renewals' => $renewals->values()];
    }
}
