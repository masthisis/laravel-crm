<div class="fi-widget">
    <h3 class="mb-2 text-lg font-bold">{{ $this->getHeading() }}</h3>
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <thead>
            <tr class="border-b border-gray-200 bg-gray-50 text-right dark:border-gray-700 dark:bg-gray-800">
                @foreach ($this->headers() as $header)
                    <th class="px-3 py-2">{{ $header }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($this->rows() as $row)
                <tr class="border-b border-gray-100 dark:border-gray-800">
                    @foreach ($row as $key => $cell)
                        <td class="px-3 py-2 @if(str_contains((string) $key, 'phone')) font-mono @endif" @if(str_contains((string) $key, 'phone')) dir="ltr" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($this->headers()) }}" class="px-3 py-4 text-center text-gray-500">موردی ثبت نشده است</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
