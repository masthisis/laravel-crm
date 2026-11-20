<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <x-filament::section>
            <x-slot:heading>Import leads from CSV</x-slot:heading>
            <x-slot:description>
                Upload a CSV, pick (or create) the source these leads came from, and optionally assign them
                right away — all to one agent or spread evenly across the team.
            </x-slot:description>
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>Recent import / assignment batches</x-slot:heading>
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;">
                        <th style="padding:8px;">When</th>
                        <th style="padding:8px;">File</th>
                        <th style="padding:8px;">Strategy</th>
                        <th style="padding:8px;">Leads</th>
                        <th style="padding:8px;">Distribution</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBatches as $batch)
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td style="padding:8px;">{{ $batch->created_at?->format('Y-m-d H:i') }}</td>
                            <td style="padding:8px;">{{ $batch->filename ?? '—' }}</td>
                            <td style="padding:8px;">{{ $batch->strategy->label() }}</td>
                            <td style="padding:8px;">{{ $batch->total_leads }}</td>
                            <td style="padding:8px;">
                                @if(is_array($batch->distribution) && count($batch->distribution) > 0)
                                    {{ collect($batch->distribution)->map(fn ($count, $id) => "agent #{$id}: {$count}")->implode(', ') }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr style="border-top:1px solid #e5e7eb;">
                            <td colspan="5" style="padding:12px; color:#6b7280;">No imports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>
</x-filament-panels::page>
