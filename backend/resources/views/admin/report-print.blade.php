@php($m = fn ($p) => $p === null ? '—' : ($p < 0 ? '−' : '').'£'.number_format(abs($p) / 100, 2))
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Business Report · {{ \App\Services\Reports::PERIODS[$period] }}</title><meta name="robots" content="noindex">
<style>body{font:13px/1.45 -apple-system,Segoe UI,Arial,sans-serif;color:#111;margin:28px;max-width:1000px}h1{font-size:22px;margin:0}h2{font-size:15px;margin:22px 0 6px;border-bottom:2px solid #111;padding-bottom:3px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:5px 6px;border-bottom:1px solid #ddd}th{color:#444}.muted{color:#666}.print{margin:12px 0}@media print{.print{display:none}body{margin:0}}</style></head>
<body>
<h1>{{ config('app.name') }} · Business Report</h1>
<div class="muted">{{ \App\Services\Reports::PERIODS[$period] }} · prepared {{ now('Europe/London')->format('j M Y, H:i') }} (UK time)</div>
<button class="print" onclick="window.print()">Print or save as PDF</button>
<h2>Sales</h2>
<table><tr><th>Today</th><th>7 days</th><th>30 days</th><th>12 months</th><th>All time</th></tr><tr>@foreach (['today', 'week', 'month', 'year', 'all'] as $k)<td>{{ $m($headline[$k]) }}</td>@endforeach</tr></table>
<h2>Company</h2>
<table>@foreach ([['Gross sales', 'grossSales'], ['Real money', 'realMoney'], ['Memberships', 'membershipRevenue'], ['Refunds', 'refunds'], ['Net revenue', 'netRevenue'], ['Discounts', 'discounts'], ['Total costs', 'totalCosts'], ['Profit', 'profit']] as [$l, $k])<tr><th>{{ $l }}</th><td>{{ $m($company[$k]) }}</td></tr>@endforeach
<tr><th>Margin</th><td>{{ $company['marginPct'] !== null ? $company['marginPct'].'%' : '—' }}</td></tr></table>
<h2>Costs</h2><table>@foreach ($company['costs'] as $l => $v)<tr><th>{{ $l }}</th><td>{{ $m($v) }}</td></tr>@endforeach</table>
<h2>Entries</h2><table>@foreach ($company['entries'] as $l => $v)<tr><th>{{ $l }}</th><td>{{ number_format($v) }}</td></tr>@endforeach</table>
<h2>Customers</h2><table>@foreach (['new' => 'New', 'active' => 'Active', 'repeat' => 'Repeat', 'inactive' => 'Inactive 30 days'] as $k => $l)<tr><th>{{ $l }}</th><td>{{ number_format($customers[$k]) }}</td></tr>@endforeach
<tr><th>Average order</th><td>{{ $m($customers['aov']) }}</td></tr><tr><th>Lifetime value</th><td>{{ $m($customers['ltv']) }}</td></tr></table>
<h2>Marketing</h2><table><tr><th>Channel</th><th>Spend</th><th>Revenue</th><th>ROAS</th><th>New customers</th></tr>@foreach ($marketing['rows'] as $r)<tr><td>{{ $r['channel'] }}</td><td>{{ $m($r['spend']) }}</td><td>{{ $m($r['revenue']) }}</td><td>{{ $r['roas'] !== null ? $r['roas'].'x' : '—' }}</td><td>{{ $r['newCustomers'] }}</td></tr>@endforeach</table>
<h2>Competitions</h2><table><tr><th>Competition</th><th>Sold</th><th>Revenue</th><th>Forecast</th><th>Break-even</th><th>Forecast profit</th></tr>
@foreach ($competitions as $c)<tr><td>{{ $c['title'] }}</td><td>{{ $c['soldPct'] }}%</td><td>{{ $m($c['revenue']) }}</td><td>{{ $c['forecastPct'] }}%</td><td>{{ $c['breakEvenTickets'] ?? '—' }}</td><td>{{ $m($c['forecastProfit']) }}</td></tr>@endforeach</table>
</body></html>
