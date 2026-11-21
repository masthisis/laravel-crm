<x-filament-panels::page>
    {{-- Filters (bug 11 #9: actually applied) --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <div>
            <x-filament::label>از تاریخ</x-filament::label>
            <input type="date" wire:model.live.debounce.500ms="date_start" wire:change="loadRows"
                   class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>تا تاریخ</x-filament::label>
            <input type="date" wire:model.live.debounce.500ms="date_end" wire:change="loadRows"
                   class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div>
            <x-filament::label>جستجوی شماره</x-filament::label>
            <input type="text" wire:model.live.debounce.500ms="phone" wire:change="loadRows" placeholder="مثلا 912…"
                   class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
        </div>
        <div class="flex items-end">
            <x-filament::button wire:click="loadRows">به‌روزرسانی</x-filament::button>
        </div>
    </div>

    <div class="mt-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 text-right dark:border-gray-700">
                <th class="px-3 py-2">#</th>
                <th class="px-3 py-2">نام</th>
                <th class="px-3 py-2">شماره</th>
                <th class="px-3 py-2">نوع انتقال</th>
                <th class="px-3 py-2">توضیحات</th>
                <th class="px-3 py-2">تاریخ ارجاع</th>
                <th class="px-3 py-2">عملیات</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="ref-{{ $row['id'] }}">
                    <td class="px-3 py-2">{{ $row['id'] }}</td>
                    <td class="px-3 py-2">
                        @if ($row['has_customer'])
                            <a href="{{ $row['customer_url'] }}" class="text-primary-600 underline">{{ $row['name'] ?? 'نامشخص' }}</a>
                        @else
                            <a href="{{ $row['register_url'] }}" class="text-gray-500 underline">ناشناس</a>
                        @endif
                    </td>
                    <td class="px-3 py-2" dir="ltr">{{ $row['phone'] }}</td>
                    <td class="px-3 py-2">
                        <span class="rounded px-2 py-1 text-xs font-bold" style="background: {{ $row['badge_color'] }}; color: {{ $row['badge_text'] }}">
                            {{ $row['lead_type_label'] }}
                        </span>
                        @if ($row['commission'] !== null)
                            <span class="ml-1 rounded bg-gray-100 px-2 py-1 text-xs dark:bg-gray-800" title="منبع: {{ $row['lead_type_label'] }}">
                                پورسانت {{ \App\Support\Persian::toPersian($row['commission']) }}٪
                            </span>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">
                            @if ($row['first_tag'])
                                اولین تعامل: {{ $row['first_tag']['label'] }} · {{ \App\Support\Jalali::format($row['first_tag']['at']) }}
                                @if ($row['first_tag']['inferred']) (بازسازی‌شده از سوابق قبل از ارجاع) @endif
                                @if ($row['latest_tag'])
                                    · تعامل مجدد: {{ $row['latest_tag']['label'] }} · {{ \App\Support\Jalali::format($row['latest_tag']['at']) }}
                                @endif
                                @if ($row['trigger']) · منبع ارجاع: {{ $row['trigger'] }} @endif
                            @else
                                (سابقه تعامل ثبت‌شده‌ای پیدا نشد)
                            @endif
                        </div>
                    </td>
                    <td class="px-3 py-2">{{ $row['description'] ?? '-' }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Jalali::format($row['referred_at']) }}</td>
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap gap-1">
                            <x-filament::button size="xs" color="danger" wire:click="openReject({{ $row['id'] }})">عدم خرید</x-filament::button>
                            <x-filament::button size="xs" wire:click="openSms({{ $row['id'] }}, 1)">ارسال پیام</x-filament::button>
                            <x-filament::button size="xs" color="gray" wire:click="dial('{{ $row['phone'] }}')">تماس</x-filament::button>
                            @unless ($row['has_customer'])
                                <a href="{{ $row['register_url'] }}" class="fi-btn fi-btn-size-xs rounded bg-gray-500 px-2 py-1 text-white">ثبت کاربر</a>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">ارجاع بازی برای شما ثبت نشده است</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- عدم خرید dialog --}}
    <x-filament::modal wire:model="reject_id" :close-button="false">
        <x-slot name="heading">عدم خرید</x-slot>
        <x-filament::label>دوره</x-filament::label>
        <select wire:model="reject_course" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
            <option value="">— انتخاب کنید —</option>
            <option value="62,63,64">اپلیکیشن</option>
            <option value="26">دوره کریپتو پامپ</option>
            <option value="48">دوره غیرحضوری دکس</option>
            <option value="60">وبینار</option>
        </select>
        <x-filament::label class="mt-3">دلیل</x-filament::label>
        <select wire:model="reject_reason" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700">
            <option value="">— انتخاب کنید —</option>
            @foreach (['قبلا خرید انجام داده اند', 'شماره اشتباه', 'عدم صلاحیت', 'عدم پاسخگویی', 'نیاز به دوره ای ندارد', 'ارتباط با ایجنت دیگر', 'ارائه مستندات خرید با خط دیگر'] as $reason)
                <option value="{{ $reason }}">{{ $reason }}</option>
            @endforeach
        </select>
        <x-slot name="footer">
            <div class="flex gap-2">
                <x-filament::button wire:click="confirmReject" color="danger">ثبت عدم خرید</x-filament::button>
                <x-filament::button wire:click="$set('reject_id', null)" color="gray">انصراف</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>

    {{-- SMS dialog (server handler per 11 #11) --}}
    <x-filament::modal wire:model="sms_id">
        <x-slot name="heading">ارسال پیام به کاربر</x-slot>
        <textarea wire:model.live="sms_text" rows="5"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 dark:bg-gray-900 dark:border-gray-700"></textarea>
        <div class="mt-1 text-xs text-gray-500">
            {{ $sms_text !== '' ? mb_strlen($sms_text) : 0 }} کاراکتر — {{ \App\Support\Persian::toPersian((string) $sms_parts) }} بخش پیامک
        </div>
        <div class="mt-3 flex flex-wrap gap-1">
            @if ($sms_id)
                <x-filament::button size="xs" wire:click="openSms({{ $sms_id }}, 1)">ویدیو معرفی</x-filament::button>
                <x-filament::button size="xs" wire:click="openSms({{ $sms_id }}, 3)">تماس بی‌پاسخ</x-filament::button>
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex gap-2">
                <x-filament::button wire:click="sendSms">ارسال</x-filament::button>
                <x-filament::button wire:click="$set('sms_id', null)" color="gray">انصراف</x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</x-filament-panels::page>
