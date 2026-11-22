@php
    $m = fn ($p) => ($p < 0 ? '−' : '').'£'.number_format(abs($p) / 100, 2);
    $th = 'text-align:left;opacity:.65;font-weight:600;padding:6px 8px 6px 0';
    $td = 'padding:6px 8px 6px 0;border-top:1px solid rgba(127,127,127,.2)';
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Upload a Settlement Report" description="A CSV from the payment provider. We find the reference (or order number), amount and date columns by their headings.">
        <form wire:submit="import" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="file" wire:model="file" accept=".csv,text/csv" aria-label="Settlement CSV">
            <x-filament::button type="submit">Import and Match</x-filament::button>
        </form>
        @error('file')<p style="color:#ef4444">{{ $message }}</p>@enderror
    </x-filament::section>
    <x-filament::section heading="Lines That Don’t Match" description="A different amount, or no order with that reference.">
        <table style="width:100%;font-size:14px;border-collapse:collapse"><tr>@foreach (['Batch', 'Reference', 'Order', 'Amount', 'Settled', 'Problem'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
        @forelse ($this->problems() as $l)<tr><td style="{{ $td }}">{{ $l->batch }}</td><td style="{{ $td }}">{{ $l->reference ?? '—' }}</td><td style="{{ $td }}">{{ $l->order_number ?? '—' }}</td><td style="{{ $td }}">{{ $m($l->amount_pence) }}</td><td style="{{ $td }}">{{ $l->settled_on?->format('j M Y') ?? '—' }}</td><td style="{{ $td }}">{{ $l->match === 'amount' ? 'Amount differs' : 'No matching order' }}</td></tr>
        @empty <tr><td style="{{ $td }}" colspan="6">Everything uploaded so far matches.</td></tr> @endforelse</table>
    </x-filament::section>
    <x-filament::section heading="Paid but Not Yet Settled" description="Card orders paid 3 to 60 days ago that no uploaded settlement report includes.">
        <table style="width:100%;font-size:14px;border-collapse:collapse"><tr>@foreach (['Order', 'Paid', 'By card', 'Reference'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
        @forelse ($this->unsettled() as $o)<tr><td style="{{ $td }}">{{ $o->number }}</td><td style="{{ $td }}">{{ $o->paid_at?->timezone('Europe/London')->format('j M Y H:i') }}</td><td style="{{ $td }}">{{ $m($o->card_pence) }}</td><td style="{{ $td }}">{{ $o->gateway_ref }}</td></tr>
        @empty <tr><td style="{{ $td }}" colspan="4">Nothing outstanding.</td></tr> @endforelse</table>
    </x-filament::section>
</x-filament-panels::page>
