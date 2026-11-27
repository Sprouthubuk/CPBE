@php
    $res = $this->results();
    $m = fn ($p) => $p === null ? '—' : ($p < 0 ? '−' : '').'£'.number_format(abs($p) / 100, 2);
    $in = 'width:100%;border-radius:8px;padding:6px 8px;background:transparent;border:1px solid rgba(127,127,127,.4)';
    $th = 'text-align:left;opacity:.65;font-weight:600;padding:6px 8px 6px 0';
    $td = 'padding:6px 8px 6px 0;border-top:1px solid rgba(127,127,127,.2)';
    $fields = [['prizeCost', 'Prize cost (£)'], ['ticketPrice', 'Ticket price (£)'], ['maxTickets', 'Maximum tickets'], ['freeSharePct', 'Free bundle tickets (% of tickets)'],
        ['promoSharePct', 'Promotional tickets (%)'], ['discountPct', 'Average discount (%)'], ['marketing', 'Marketing (£)'], ['vatPct', 'VAT on revenue (%)'],
        ['feePct', 'Payment fee (%)'], ['feeFixed', 'Fee per order (£)'], ['avgOrder', 'Average order (£)'],
        ['guaranteedPrizes', 'Guaranteed minimum prizes (£)'], ['cashAltValue', 'Cash alternative (£)'], ['cashAltSharePct', 'Winners taking the cash (%)']];
@endphp
<x-filament-panels::page>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <label for="load" style="opacity:.7">Start Option 1 from a competition:</label>
        <select id="load" wire:model.live="load" style="{{ $in }};max-width:360px"><option value="">Choose…</option>@foreach ($this->competitions() as $id => $t)<option value="{{ $id }}">{{ $t }}</option>@endforeach</select>
        @if (count($options) < 3)<x-filament::button size="sm" color="gray" wire:click="addOption">Add an option</x-filament::button>@endif
    </div>
    <div style="display:grid;grid-template-columns:repeat({{ count($options) }},minmax(240px,1fr));gap:16px;overflow-x:auto">
        @foreach ($options as $i => $o)
            <x-filament::section>
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input aria-label="Option name" style="{{ $in }};font-weight:700" wire:model.live.debounce.400ms="options.{{ $i }}.name">
                    @if (count($options) > 1)<x-filament::button size="xs" color="gray" wire:click="removeOption({{ $i }})">Remove</x-filament::button>@endif</div>
                @foreach ($fields as [$key, $label])
                    <label style="display:block;margin-bottom:6px;font-size:13px"><span style="opacity:.7">{{ $label }}</span><input inputmode="decimal" style="{{ $in }}" wire:model.live.debounce.400ms="options.{{ $i }}.{{ $key }}"></label>
                @endforeach
            </x-filament::section>
        @endforeach
    </div>
    <x-filament::section heading="Side by Side">
        <div style="overflow-x:auto"><table style="width:100%;font-size:14px;border-collapse:collapse">
            <tr><th style="{{ $th }}"></th>@foreach ($res as $r)<th style="{{ $th }}">{{ $r['name'] }}</th>@endforeach</tr>
            <tr><td style="{{ $td }}">Break-even</td>@foreach ($res as $r)<td style="{{ $td }}">{{ $r['breakEvenTickets'] !== null ? number_format($r['breakEvenTickets']).' tickets ('.$r['breakEvenPct'].'%)' : 'Never (costs more than a sell-out brings in)' }}</td>@endforeach</tr>
            <tr><td style="{{ $td }}">Sales for a 20% margin</td>@foreach ($res as $r)<td style="{{ $td }}">{{ $r['for20'] ? number_format($r['for20']['tickets']).' ('.$r['for20']['pct'].'%)' : 'Not reachable' }}</td>@endforeach</tr>
            <tr><td style="{{ $td }}">Worst loss (10% to 100% sold)</td>@foreach ($res as $r)<td style="{{ $td }};{{ $r['worstLoss'] < 0 ? 'color:#ef4444' : '' }}">{{ $r['worstLoss'] < 0 ? $m($r['worstLoss']).' · loses money up to '.max(array_column($r['lossScenarios'], 'pct')).'% sold' : 'No loss at any level' }}</td>@endforeach</tr>
            <tr><td style="{{ $td }}">Sales for a 30% margin</td>@foreach ($res as $r)<td style="{{ $td }}">{{ $r['for30'] ? number_format($r['for30']['tickets']).' ('.$r['for30']['pct'].'%)' : 'Not reachable' }}</td>@endforeach</tr>
            <tr><td style="{{ $td }}">Revenue per ticket (all entries)</td>@foreach ($res as $r)<td style="{{ $td }}">{{ $m($r['effectivePrice']) }}</td>@endforeach</tr>
            @foreach ([30, 40, 50, 60, 70, 80, 90, 100] as $lvl)
                <tr><td style="{{ $td }}">Profit at {{ $lvl }}% sold</td>@foreach ($res as $r)@php($l = collect($r['levels'])->firstWhere('pct', $lvl))<td style="{{ $td }};{{ $l['profit'] < 0 ? 'color:#ef4444' : '' }}">{{ $m($l['profit']) }}{{ $l['margin'] !== null ? ' ('.$l['margin'].'%)' : '' }}</td>@endforeach</tr>
            @endforeach
        </table></div>
    </x-filament::section>
    @foreach ($res as $r)
        <x-filament::section :heading="$r['name'].' · every sell-through level'" collapsible collapsed>
            <table style="width:100%;font-size:13px;border-collapse:collapse"><tr>@foreach (['Sold', 'Tickets', 'Paid tickets', 'Revenue', 'VAT', 'Fees', 'All costs', 'Profit', 'Margin'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
            @foreach ($r['levels'] as $l)<tr><td style="{{ $td }}">{{ $l['pct'] }}%</td><td style="{{ $td }}">{{ number_format($l['sold']) }}</td><td style="{{ $td }}">{{ number_format($l['paid']) }}</td><td style="{{ $td }}">{{ $m($l['revenue']) }}</td><td style="{{ $td }}">{{ $m($l['vat']) }}</td><td style="{{ $td }}">{{ $m($l['fees']) }}</td><td style="{{ $td }}">{{ $m($l['costs']) }}</td><td style="{{ $td }}">{{ $m($l['profit']) }}</td><td style="{{ $td }}">{{ $l['margin'] !== null ? $l['margin'].'%' : '—' }}</td></tr>@endforeach
            </table>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
