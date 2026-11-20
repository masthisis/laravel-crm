<div class="p-4 rounded-xl bg-white shadow-sm dark:bg-gray-800 filament-widgets-component">
    <h3 class="text-lg font-semibold mb-2">Renewals coming up</h3>
    @forelse ($renewals as $row)
        <div class="flex justify-between py-1 border-b border-gray-100 dark:border-gray-700">
            <span class="font-medium">{{ $row['customer'] }}</span>
            <span class="text-sm text-warning-600 dark:text-warning-400">{{ $row['subscriptions']->join(' · ') }}</span>
        </div>
    @empty
        <p class="text-sm text-gray-500">No subscriptions renewing in the next {{ config('crm.subscriptions.renewal_window', 30) }} days.</p>
    @endforelse
</div>
