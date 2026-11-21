<x-filament-panels::page>
    <form wire:submit.prevent="calculate" class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div>
            <x-filament::label>ارجاع از</x-filament::label>
            <input type="date" wire:model="referral_from" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>ارجاع تا</x-filament::label>
            <input type="date" wire:model="referral_to" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>خرید از</x-filament::label>
            <input type="date" wire:model="purchase_from" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>خرید تا</x-filament::label>
            <input type="date" wire:model="purchase_to" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>تعریف خرید</x-filament::label>
            <select wire:model="purchase_kind" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
                <option value="order">سفارش پرداخت‌شده</option>
                <option value="subscription">اشتراک اپلیکیشن</option>
            </select>
        </div>
        <div>
            <x-filament::label>نما</x-filament::label>
            <select wire:model="report_view" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
                <option value="history">سابقه ارجاع</option>
                <option value="owner">مسئول هنگام خرید</option>
            </select>
        </div>
        <div>
            <x-filament::label>گروه‌بندی</x-filament::label>
            <select wire:model="grouping" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
                <option value="agent">به تفکیک کارشناس</option>
                <option value="weekly">هفتگی</option>
                <option value="monthly">ماهانه (شمسی)</option>
            </select>
        </div>
        <div class="flex items-end">
            <x-filament::button type="submit">محاسبه گزارش</x-filament::button>
        </div>
    </form>

    @if ($calculated)
        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                        <th class="px-3 py-2">دوره / کارشناس</th>
                        <th class="px-3 py-2">لیدهای ارجاع‌شده</th>
                        <th class="px-3 py-2">تبدیل‌شده</th>
                        <th class="px-3 py-2">خریداران</th>
                        <th class="px-3 py-2">نرخ تبدیل</th>
                        <th class="px-3 py-2">اشتراک</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-3 py-2">{{ $row['label'] }}</td>
                            <td class="px-3 py-2">{{ $row['referred'] }}</td>
                            <td class="px-3 py-2">{{ $row['converted'] }}</td>
                            <td class="px-3 py-2">{{ $row['buyers'] }}</td>
                            <td class="px-3 py-2">{{ $row['rate'] }}٪</td>
                            <td class="px-3 py-2">{{ $row['subscriptions'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-4 text-center text-gray-500">داده‌ای برای این بازه پیدا نشد</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
