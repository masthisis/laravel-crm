<x-filament-panels::page>
    <div class="flex flex-wrap gap-2">
        @foreach (['current' => 'ماه جاری', 'previous' => 'ماه قبل', 'all' => 'همه'] as $key => $label)
            <button wire:click="$set('view_mode', '{{ $key }}'); loadRows()"
                    class="rounded px-3 py-1.5 text-sm {{ $view_mode === $key ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-800' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500">پورسانت کل</div>
            <div class="text-lg font-bold">{{ $totals['earned'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div class="text-xs text-gray-500">در انتظار بررسی</div>
            <div class="text-lg font-bold">{{ \App\Support\Persian::toPersian((string) $totals['review']) }}</div>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">مشتری</th><th class="px-3 py-2">کد تخفیف</th><th class="px-3 py-2">منبع</th>
                <th class="px-3 py-2">درصد</th><th class="px-3 py-2">مبلغ پرداخت</th><th class="px-3 py-2">پورسانت</th><th class="px-3 py-2">تاریخ پرداخت</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2">{{ $row['customer'] }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['code'] }}</td>
                    <td class="px-3 py-2">{{ $row['source'] }}</td>
                    <td class="px-3 py-2">{{ $row['rate'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid_amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid_at'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">پورسانتی در این بازه ندارید</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-lg font-bold">سوابق پرداخت</h3>
    <div class="mt-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">ماه</th><th class="px-3 py-2">مبلغ</th><th class="px-3 py-2">تاریخ</th><th class="px-3 py-2">ارجاع</th>
            </tr></thead>
            <tbody>
            @forelse ($payoutHistory as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    <td class="px-3 py-2" dir="ltr">{{ $row['month'] }}</td>
                    <td class="px-3 py-2">{{ $row['amount'] }}</td>
                    <td class="px-3 py-2">{{ $row['paid_on'] }}</td>
                    <td class="px-3 py-2">{{ $row['reference'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
