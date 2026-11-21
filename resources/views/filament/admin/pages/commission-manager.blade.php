<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <div>
            <x-filament::label>ماه (شمسی)</x-filament::label>
            <input type="text" wire:model.live.debounce.500ms="month" wire:change="loadRows" placeholder="1405-09" dir="ltr"
                   class="mt-1 w-40 rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <x-filament::button wire:click="syncNow" color="gray">همگام‌سازی</x-filament::button>
    </div>

    <h3 class="mt-6 text-lg font-bold">به تفکیک کارشناس</h3>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">کارشناس</th><th class="px-3 py-2">تعداد فروش</th><th class="px-3 py-2">پورسانت</th>
                <th class="px-3 py-2">پرداخت شده</th><th class="px-3 py-2">باقی‌مانده</th><th class="px-3 py-2">بررسی</th><th class="px-3 py-2"></th>
            </tr></thead>
            <tbody>
            @forelse ($perAgent as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2">{{ $row['agent'] }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Persian::toPersian((string) $row['sales']) }}</td>
                    <td class="px-3 py-2">{{ $row['earned'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid'] }}</td>
                    <td class="px-3 py-2">{{ $row['remaining'] }}</td>
                    <td class="px-3 py-2">{{ $row['review'] > 0 ? \App\Support\Persian::toPersian((string) $row['review']) : '—' }}</td>
                    <td class="px-3 py-2">
                        <x-filament::button size="xs" wire:click="$set('payout_agent', {{ (int) (\App\Models\User::query()->where('name', $row['agent'])->value('id') ?? 0) }})">ثبت پرداخت</x-filament::button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">درآمدی در این ماه ثبت نشده</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-lg font-bold">به تفکیک منبع لید</h3>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">منبع</th><th class="px-3 py-2">تعداد</th><th class="px-3 py-2">پورسانت</th>
            </tr></thead>
            <tbody>
            @forelse ($perSource as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2">{{ $row['source'] }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Persian::toPersian((string) $row['count']) }}</td>
                    <td class="px-3 py-2">{{ $row['commission'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="px-3 py-4 text-center text-gray-500">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-lg font-bold">جزئیات تراکنش‌ها</h3>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">مشتری</th><th class="px-3 py-2">کد تخفیف</th><th class="px-3 py-2">منبع</th>
                <th class="px-3 py-2">درصد</th><th class="px-3 py-2">مبلغ پرداخت</th><th class="px-3 py-2">پورسانت</th>
                <th class="px-3 py-2">وضعیت</th><th class="px-3 py-2">ماه</th>
            </tr></thead>
            <tbody>
            @forelse ($transactions as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2">{{ $row['customer'] }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['code'] }}</td>
                    <td class="px-3 py-2">{{ $row['source'] }}</td>
                    <td class="px-3 py-2">{{ $row['rate'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid_amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['status'] }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['month'] }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-3 py-4 text-center text-gray-500">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-lg font-bold">سوابق پرداخت</h3>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">کارشناس</th><th class="px-3 py-2">ماه</th><th class="px-3 py-2">مبلغ</th><th class="px-3 py-2">تاریخ</th><th class="px-3 py-2">ارجاع</th>
            </tr></thead>
            <tbody>
            @forelse ($payouts as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2">{{ $row['agent'] }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['month'] }}</td>
                    <td class="px-3 py-2">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid_on'] }}</td>
                    <td class="px-3 py-2">{{ $row['reference'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <x-filament::modal wire:model="payout_agent">
        <x-slot name="heading">ثبت پرداخت پورسانت — {{ $month }}</x-slot>
        <x-filament::label>مبلغ (تومان)</x-filament::label>
        <input type="text" wire:model="payout_amount" dir="ltr" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <x-filament::label class="mt-3">تاریخ پرداخت</x-filament::label>
        <input type="date" wire:model="payout_paid_on" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <x-filament::label class="mt-3">شماره پیگیری / مرجع</x-filament::label>
        <input type="text" wire:model="payout_reference" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <x-slot name="footer">
            <div class="flex gap-2">
                <x-filament::button wire:click="recordPayout">ثبت</x-filament::button>
                <x-filament::button wire:click="$set('payout_agent', null)" color="gray">انصراف</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
