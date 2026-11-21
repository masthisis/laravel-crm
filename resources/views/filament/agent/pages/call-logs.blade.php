<x-filament-panels::page>
    <div class="flex flex-wrap gap-1">
        @foreach (['incoming' => 'دریافتی', 'outgoing' => 'برقرار شده', 'missed' => 'بی‌پاسخ', 'all' => 'همه'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="rounded px-3 py-1.5 text-sm {{ $tab === $key ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
        <input type="date" wire:model.live.debounce.500ms="date_start" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <input type="date" wire:model.live.debounce.500ms="date_end" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <input type="text" wire:model.live.debounce.500ms="phone" wire:change="loadRows" placeholder="جستجوی شماره" dir="ltr" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">نام</th>
                <th class="px-3 py-2">شماره</th>
                <th class="px-3 py-2">زمان</th>
                <th class="px-3 py-2">جهت</th>
                <th class="px-3 py-2">مدت</th>
                <th class="px-3 py-2">داخلی پاسخگو</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="call-{{ $row['id'] }}">
                    <td class="px-3 py-2">
                        @if ($row['name'] !== 'ناشناس')
                            <span>{{ $row['name'] }}</span>
                        @else
                            <a href="/agent/register/{{ $row['phone'] }}" class="text-gray-500 underline">ناشناس</a>
                        @endif
                    </td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['phone'] }}</td>
                    <td class="px-3 py-2" title="{{ \App\Support\Jalali::format($row['called_at']) }}">{{ $row['called_at']?->diffForHumans() }}</td>
                    <td class="px-3 py-2">{{ $row['direction'] === 'incoming' ? '↓ ورودی' : '↑ خروجی' }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['duration'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ $row['accepted'] ?? 'ندارد' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">تماسی در این بازه پیدا نشد</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
