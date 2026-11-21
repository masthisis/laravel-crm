<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <input type="date" wire:model.live.debounce.500ms="date_start" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        <input type="date" wire:model.live.debounce.500ms="date_end" wire:change="loadRows" class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
    </div>

    {{-- Chart: sales per operator --}}
    <div class="mt-6">
        <h3 class="text-lg font-bold">فروش به تفکیک کارشناس</h3>
        @forelse ($perOperator as $op)
            @php $max = collect($perOperator)->max('count') ?: 1; @endphp
            <div class="mt-2 flex items-center gap-2" wire:key="op-{{ md5($op['name']) }}">
                <span class="w-40 shrink-0 text-sm">{{ $op['name'] }}</span>
                <div class="h-4 rounded bg-primary-500" style="width: {{ max(4, (int) ($op['count'] / $max * 70)) }}%"></div>
                <span class="text-sm">{{ \App\Support\Persian::toPersian((string) $op['count']) }}</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">فروشی در این بازه ثبت نشده</p>
        @endforelse
    </div>

    {{-- My sales with conflict detection --}}
    <div class="mt-8 overflow-x-auto">
        <h3 class="mb-2 text-lg font-bold">فروش‌های من</h3>
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">خریدار</th>
                <th class="px-3 py-2">شماره</th>
                <th class="px-3 py-2">تاریخ</th>
                <th class="px-3 py-2">تخفیف</th>
                <th class="px-3 py-2">کد معرف</th>
                <th class="px-3 py-2">محصولات</th>
                <th class="px-3 py-2">مبلغ</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($mySales as $row)
                <tr class="border-b border-gray-100 {{ $row['conflict'] ? 'bg-red-50 dark:bg-red-900/20' : '' }} dark:border-gray-800" wire:key="sale-{{ $row['id'] }}">
                    <td class="px-3 py-2">
                        {{ $row['customer'] }}
                        @if ($row['conflict'])
                            <span class="mr-1 rounded bg-red-600 px-2 py-0.5 text-xs text-white" title="{{ $row['conflict_tooltip'] }}">⚠ تعارض</span>
                        @endif
                    </td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['phone'] }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Jalali::format($row['invoice_date']) }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['discount'] ?? '-' }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['reagent_code'] ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $row['products'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Persian::toman($row['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">فروشی در این بازه ندارید</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
