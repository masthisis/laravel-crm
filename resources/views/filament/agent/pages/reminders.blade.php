<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <input type="date" wire:model.live.debounce.500ms="date_start" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <input type="date" wire:model.live.debounce.500ms="date_end" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">نام</th>
                <th class="px-3 py-2">شماره</th>
                <th class="px-3 py-2">وضعیت</th>
                <th class="px-3 py-2">تاریخ یادآوری</th>
                <th class="px-3 py-2">مشاهده</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="rem-{{ $row['id'] }}">
                    <td class="px-3 py-2">{{ $row['name'] }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['phone'] }}</td>
                    <td class="px-3 py-2">{{ $row['account_status'] === 'verified' ? 'تایید شده' : 'تایید نشده' }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Jalali::format($row['reminder_at']) }}</td>
                    <td class="px-3 py-2"><a href="{{ $row['url'] }}" class="text-primary-600 underline">مشاهده</a></td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">یادآوری‌ای در این بازه ندارید</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
