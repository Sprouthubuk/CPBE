@props(['title', 'sub' => null, 'numbers' => []])
@php($shown = array_slice($numbers, 0, 60))
<table class="cp-tickets" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#0E1311">
<tr>
<td>
<p class="cp-tickets-title">{{ $title }}</p>
@if ($sub)
<p class="cp-tickets-sub">{{ $sub }}</p>
@endif
@foreach ($shown as $n)<span class="cp-chip">#{{ $n }}</span> @endforeach
@if (count($numbers) > count($shown))
<span class="cp-more">and {{ number_format(count($numbers) - count($shown)) }} more in My Tickets</span>
@endif
</td>
</tr>
</table>
