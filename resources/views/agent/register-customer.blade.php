<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>ثبت کاربر — {{ $phone }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="mx-auto max-w-3xl p-6">
    <h1 class="text-xl font-bold">ثبت کاربر — شماره <span dir="ltr">{{ $phone }}</span></h1>

    @if ($engagement && $engagement['first'])
        <p class="mt-3 text-sm text-gray-600">
            اولین تعامل: {{ $engagement['first']['label'] }} · {{ \App\Support\Jalali::format($engagement['first']['at']) }}
            @if ($engagement['latest']) · تعامل مجدد: {{ $engagement['latest']['label'] }} · {{ \App\Support\Jalali::format($engagement['latest']['at']) }} @endif
        </p>
    @endif

    @if ($calls->isNotEmpty())
        <h2 class="mt-4 font-bold">تماس‌های قبلی</h2>
        <table class="mt-2 w-full text-sm">
            <thead><tr class="border-b border-gray-200 text-right">
                <th class="px-3 py-2">شماره</th><th class="px-3 py-2">کارشناس</th><th class="px-3 py-2">مدت</th><th class="px-3 py-2">تاریخ</th>
            </tr></thead>
            <tbody>
            @foreach ($calls as $call)
                <tr class="border-b border-gray-100">
                    <td class="px-3 py-2" dir="ltr">{{ $call['phone'] }}</td>
                    <td class="px-3 py-2">{{ $call['operator'] ?? '—' }}</td>
                    <td class="px-3 py-2" dir="ltr">{{ $call['duration'] ?: '—' }}</td>
                    <td class="px-3 py-2">{{ \App\Support\Jalali::format($call['called_at']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <form method="post" action="{{ url('/agent/register/'.$phone) }}" class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        @csrf
        <div>
            <label class="text-sm">نام</label>
            <input name="name" required class="mt-1 w-full rounded border px-3 py-2">
        </div>
        <div>
            <label class="text-sm">شماره دوم</label>
            <input name="phone2" dir="ltr" class="mt-1 w-full rounded border px-3 py-2">
        </div>
        <div>
            <label class="text-sm">شماره سوم</label>
            <input name="phone3" dir="ltr" class="mt-1 w-full rounded border px-3 py-2">
        </div>
        <div>
            <label class="text-sm">آدرس</label>
            <input name="address" class="mt-1 w-full rounded border px-3 py-2">
        </div>
        <div class="md:col-span-2">
            <button class="rounded bg-indigo-600 px-4 py-2 text-white">ثبت کاربر</button>
        </div>
    </form>
</div>
</body>
</html>
