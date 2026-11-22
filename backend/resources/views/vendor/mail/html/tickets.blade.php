@props(['title', 'sub' => null, 'numbers' => []])
@php($shown = array_slice($numbers, 0, 60))
<table class="v9-tickets" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#0E1311">
<tr>
<td>
<p class="v9-tickets-title">{{ $title }}</p>
@if ($sub)
<p class="v9-tickets-sub">{{ $sub }}</p>
@endif
@foreach ($shown as $n)<span class="v9-chip">#{{ $n }}</span> @endforeach
@if (count($numbers) > count($shown))
<span class="v9-more">and {{ number_format(count($numbers) - count($shown)) }} more in My Tickets</span>
@endif
</td>
</tr>
</table>
