<ul class="space-y-1 text-sm">
    @foreach ($errors as $error)
        <li>
            <span class="font-mono">{{ $error['ref'] ?? '—' }}</span>
            — {{ $error['reason'] ?? 'Unknown error' }}
        </li>
    @endforeach
</ul>
