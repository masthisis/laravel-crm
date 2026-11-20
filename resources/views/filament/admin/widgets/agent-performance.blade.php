<div {{ $attributes }} class="fi-widget overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900">
    <div class="px-4 py-3 text-sm font-semibold text-gray-950 dark:text-white">
        Agent performance — assigned leads, won deals, conversion rate &amp; earned commissions
    </div>
    <table style="width:100%; border-collapse:collapse;">
        <thead>
            <tr style="background:#f9fafb; text-align:left;">
                <th style="padding:8px 16px; font-size:12px; color:#6b7280;">Agent</th>
                <th style="padding:8px 16px; font-size:12px; color:#6b7280;">Assigned</th>
                <th style="padding:8px 16px; font-size:12px; color:#6b7280;">Won</th>
                <th style="padding:8px 16px; font-size:12px; color:#6b7280;">Conversion</th>
                <th style="padding:8px 16px; font-size:12px; color:#6b7280;">Commissions earned</th>
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
                <tr style="border-top:1px solid #f3f4f6;">
                    <td style="padding:8px 16px;">{{ $agent['name'] }}</td>
                    <td style="padding:8px 16px;">{{ $agent['assigned'] }}</td>
                    <td style="padding:8px 16px;">{{ $agent['won'] }}</td>
                    <td style="padding:8px 16px;">
                        {{ $agent['conversion'] === null ? '—' : $agent['conversion'].'%' }}
                    </td>
                    <td style="padding:8px 16px;">{{ config('crm.currency', 'USD') }} {{ $agent['commissions'] }}</td>
                </tr>
            @empty
                <tr style="border-top:1px solid #f3f4f6;">
                    <td colspan="5" style="padding:16px; color:#6b7280;">
                        No agents yet — create sales_staff users under Administration → Users.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
