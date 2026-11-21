<x-filament-panels::page>
    <div class="flex flex-wrap gap-2 text-sm">
        <span class="rounded bg-gray-100 px-3 py-1.5 dark:bg-gray-800">تماس گرفته نشده: {{ \App\Support\Persian::toPersian((string) $notCalledCount) }}</span>
        @foreach ($outcomeCounts as $id => $count)
            <span class="rounded bg-gray-100 px-3 py-1.5 dark:bg-gray-800">{{ $outcomes[$id] ?? $id }}: {{ \App\Support\Persian::toPersian((string) $count) }}</span>
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-4">
        <input type="date" wire:model.live.debounce.500ms="date_start" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <input type="date" wire:model.live.debounce.500ms="date_end" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <select wire:model.live="status" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
            <option value="oncall">تماس گرفته نشده</option>
            <option value="all">همه</option>
            @foreach ($outcomes as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">نام</th>
                <th class="px-3 py-2">شماره</th>
                <th class="px-3 py-2">دسته</th>
                <th class="px-3 py-2">تاریخ</th>
                <th class="px-3 py-2">وضعیت</th>
                <th class="px-3 py-2">عملیات</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="oncall-{{ $row['id'] }}">
                    <td class="px-3 py-2">
                        @if ($row['has_customer'])
                            <a href="{{ $row['customer_url'] }}" class="text-primary-600 underline">{{ $row['name'] }}</a>
                        @else
                            <a href="{{ $row['register_url'] }}" class="text-gray-500 underline">ناشناس</a>
                        @endif
                    </td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['phone'] }}</td>
                    <td class="px-3 py-2">{{ $row['category'] ?? '—' }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Jalali::format($row['queued_at']) }}</td>
                    <td class="px-3 py-2">{{ $row['status'] }}</td>
                    <td class="px-3 py-2">
                        @if ($row['has_customer'])
                            <x-filament::button size="xs" wire:click="$set('outcome_row', {{ $row['id'] }})">ثبت وضعیت</x-filament::button>
                        @else
                            <span class="text-xs text-gray-500">ابتدا کاربر ثبت کنید</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">شماره‌ای در صف تماس ندارید</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <x-filament::modal wire:model="outcome_row">
        <x-slot name="heading">ثبت وضعیت تماس</x-slot>
        <select wire:model="outcome_status" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
            @foreach ($outcomes as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <textarea wire:model="outcome_note" rows="3" placeholder="یادداشت (اختیاری)" class="mt-3 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700"></textarea>
        <x-slot name="footer">
            <div class="flex gap-2">
                <x-filament::button wire:click="recordOutcome">ثبت</x-filament::button>
                <x-filament::button wire:click="$set('outcome_row', null)" color="gray">انصراف</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
