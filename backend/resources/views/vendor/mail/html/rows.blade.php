@props(['rows' => [], 'totalLabel' => null, 'totalValue' => null])
<table class="v9-rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as [$label, $value])
<tr><td>{{ $label }}</td><td class="v9-val" align="right">{{ $value }}</td></tr>
@endforeach
@if ($totalLabel !== null)
<tr class="v9-total"><td>{{ $totalLabel }}</td><td class="v9-val" align="right">{{ $totalValue }}</td></tr>
@endif
</table>
