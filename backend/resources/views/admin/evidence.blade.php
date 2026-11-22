<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Evidence Pack · {{ $order->number }}</title>
<meta name="robots" content="noindex">
<style>
  body{font:14px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:#111;margin:32px;max-width:960px}
  h1{font-size:22px;margin:0 0 4px} h2{font-size:16px;margin:28px 0 8px;border-bottom:2px solid #111;padding-bottom:4px}
  table{border-collapse:collapse;width:100%} th,td{text-align:left;vertical-align:top;padding:6px 8px;border-bottom:1px solid #ddd}
  th{width:220px;color:#444;font-weight:600} .muted{color:#666} .print{margin:16px 0}
  @media print{.print{display:none} body{margin:0}}
</style>
</head>
<body>
<h1>Evidence Pack: Order {{ $order->number }}</h1>
<div class="muted">{{ config('app.name') }} · prepared {{ now('Europe/London')->format('j M Y, H:i') }} (UK time)</div>
<button class="print" onclick="window.print()">Print or save as PDF</button>

@foreach ($disputes as $d)
<h2>Case {{ $d->reference ?? '#'.$d->id }}</h2>
<table>
<tr><th>Type</th><td>{{ \App\Models\Dispute::KINDS[$d->kind] ?? $d->kind }}</td></tr>
<tr><th>Amount</th><td>£{{ number_format($d->amount_pence / 100, 2) }}</td></tr>
<tr><th>Reason given</th><td>{{ $d->reason }}</td></tr>
<tr><th>Status</th><td>{{ \App\Models\Dispute::STATUSES[$d->status] ?? $d->status }}</td></tr>
</table>
@endforeach

<h2>The Player</h2>
<table>@foreach ($e['player'] as $k => $v)<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>

<h2>The Order</h2>
<table>@foreach ($e['order'] as $k => $v)<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>

<h2>Tickets Issued</h2>
<table><tr><th>Competition</th><th>Tickets</th><th>Skill question answer</th></tr>
@foreach ($e['lines'] as $l)<tr><td>{{ $l['competition'] }} ({{ $l['quantity'] }})</td><td>{{ $l['tickets'] ?: '—' }}</td><td>{{ $l['skill'] }}</td></tr>@endforeach
</table>

<h2>Safer Play Settings</h2>
<table>@foreach ($e['saferPlay'] as $k => $v)<tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach</table>

<h2>Wins</h2>
@if (count($e['wins']))
<table><tr><th>Prize</th><th>Value</th><th>When</th></tr>@foreach ($e['wins'] as $w)<tr><td>{{ $w['prize'] }}</td><td>{{ $w['value'] }}</td><td>{{ $w['at'] }}</td></tr>@endforeach</table>
@else <p class="muted">No wins.</p> @endif

<h2>Wallet History (latest 30)</h2>
<table><tr><th>When</th><th>What</th><th>Balance</th><th>Amount</th></tr>
@foreach ($e['credit'] as $c)<tr><td>{{ $c['at'] }}</td><td>{{ $c['what'] }}</td><td>{{ $c['bucket'] }}</td><td>{{ $c['amount'] }}</td></tr>@endforeach
</table>
</body>
</html>
