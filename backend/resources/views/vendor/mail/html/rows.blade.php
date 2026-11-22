@props(['rows' => [], 'totalLabel' => null, 'totalValue' => null])
<table class="cp-rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($rows as [$label, $value])
<tr><td>{{ $label }}</td><td class="cp-val" align="right">{{ $value }}</td></tr>
@endforeach
@if ($totalLabel !== null)
<tr class="cp-total"><td>{{ $totalLabel }}</td><td class="cp-val" align="right">{{ $totalValue }}</td></tr>
@endif
</table>
