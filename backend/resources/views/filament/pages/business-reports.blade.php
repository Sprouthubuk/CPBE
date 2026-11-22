@php
    $d = $this->data();
    $m = fn ($p) => $p === null ? '—' : ($p < 0 ? '−' : '').'£'.number_format(abs($p) / 100, 2);
    $pct = fn ($v) => $v === null ? '—' : $v.'%';
    $delta = function ($now, $before) { if (! $before) return null; $c = round(($now - $before) * 100 / abs($before), 1); return ($c >= 0 ? '+' : '').$c.'%'; };
    $k = 'display:flex;flex-wrap:wrap;gap:12px';
    $tile = 'flex:1 1 170px;padding:12px 14px;border:1px solid rgba(127,127,127,.25);border-radius:10px';
    $lab = 'opacity:.65;font-size:12px';
    $big = 'font-size:22px;font-weight:700';
    $th = 'text-align:left;opacity:.65;font-weight:600;padding:6px 8px 6px 0';
    $td = 'padding:6px 8px 6px 0;border-top:1px solid rgba(127,127,127,.2)';
@endphp
<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
        <div style="display:flex;flex-wrap:wrap;gap:6px">
            @foreach (['overview' => 'Overview', 'competitions' => 'Competitions', 'customers' => 'Customers', 'marketing' => 'Marketing', 'operations' => 'Operations', 'detail' => 'Detailed Reports'] as $key => $label)
                <x-filament::button size="sm" :color="$tab === $key ? 'primary' : 'gray'" wire:click="$set('tab', '{{ $key }}')">{{ $label }}</x-filament::button>
            @endforeach
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <select wire:model.live="period" aria-label="Period" style="border-radius:8px;padding:6px 10px;background:transparent;border:1px solid rgba(127,127,127,.4)">
                @foreach (\App\Services\Reports::PERIODS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <x-filament::button size="sm" color="gray" tag="a" :href="route('admin.reports.export', ['what' => 'orders', 'format' => 'xlsx', 'period' => $period])">Excel</x-filament::button>
            <x-filament::button size="sm" color="gray" tag="a" :href="route('admin.reports.export', ['what' => 'orders', 'format' => 'csv', 'period' => $period])">CSV</x-filament::button>
            <x-filament::button size="sm" color="gray" tag="a" target="_blank" :href="route('admin.reports.print', ['period' => $period])">PDF</x-filament::button>
        </div>
    </div>

    @if ($tab === 'overview')
        @php($c = $d['company'])
        @php($p = $d['previous'])
        @php($h = $d['headline'])
        <x-filament::section heading="Sales">
            <div style="{{ $k }}">
                @foreach (['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days', 'year' => 'Last 12 months', 'all' => 'All time'] as $key => $label)
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $m($h[$key]) }}</div></div>
                @endforeach
                <div style="{{ $tile }}"><div style="{{ $lab }}">This month against target</div><div style="{{ $big }}">{{ $h['target'] ? $pct($h['targetPct']) : 'No target' }}</div><div style="{{ $lab }}">{{ $m($h['thisMonth']) }}{{ $h['target'] ? ' of '.$m($h['target']) : ' · set one in Settings' }}</div></div>
            </div>
        </x-filament::section>
        <x-filament::section :heading="'Company · '.\App\Services\Reports::PERIODS[$period]" description="Against the previous period of the same length.">
            <div style="{{ $k }}">
                @foreach ([['Gross sales', 'grossSales'], ['Net revenue', 'netRevenue'], ['Costs', 'totalCosts'], ['Profit', 'profit'], ['Discounts', 'discounts'], ['Refunds', 'refunds'], ['Memberships', 'membershipRevenue']] as [$label, $key])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $m($c[$key]) }}</div><div style="{{ $lab }}">{{ $delta($c[$key], $p[$key]) ?? 'no earlier figure' }}</div></div>
                @endforeach
                <div style="{{ $tile }}"><div style="{{ $lab }}">Margin</div><div style="{{ $big }}">{{ $pct($c['marginPct']) }}</div><div style="{{ $lab }}">was {{ $pct($p['marginPct']) }}</div></div>
            </div>
        </x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
            <x-filament::section heading="Entries">
                <table style="width:100%;font-size:14px;border-collapse:collapse">@foreach ($c['entries'] as $label => $n)<tr><td style="{{ $td }}">{{ $label }}</td><td style="{{ $td }};text-align:right">{{ number_format($n) }}</td></tr>@endforeach</table>
            </x-filament::section>
            <x-filament::section heading="Costs" description="Payment fees are estimated; entered costs are marked actual, estimated or forecast.">
                <table style="width:100%;font-size:14px;border-collapse:collapse">@foreach ($c['costs'] as $label => $n)<tr><td style="{{ $td }}">{{ $label }}</td><td style="{{ $td }};text-align:right">{{ $m($n) }}</td></tr>@endforeach
                    <tr><td style="{{ $td }}">of which actual / estimated / forecast</td><td style="{{ $td }};text-align:right">{{ $m($c['costBasis']['actual']) }} / {{ $m($c['costBasis']['estimated']) }} / {{ $m($c['costBasis']['forecast']) }}</td></tr></table>
            </x-filament::section>
        </div>
        <x-filament::section heading="Sales Trend">
            <div style="display:flex;gap:6px;margin-bottom:10px">
                @foreach (['hour' => 'Hourly', 'day' => 'Daily', 'week' => 'Weekly'] as $key => $label)
                    <x-filament::button size="xs" :color="$trend === $key ? 'primary' : 'gray'" wire:click="$set('trend', '{{ $key }}')">{{ $label }}</x-filament::button>
                @endforeach
            </div>
            @php($max = max(1, max($d['trend'])))
            <div style="display:flex;align-items:flex-end;gap:2px;height:140px" role="img" aria-label="Sales trend">
                @foreach ($d['trend'] as $label => $v)
                    <div title="{{ $label }}: {{ $m($v) }}" style="flex:1;background:rgba(var(--primary-500),.85);min-height:1px;height:{{ max(1, round($v * 100 / $max)) }}%"></div>
                @endforeach
            </div>
            <div style="display:flex;justify-content:space-between;{{ $lab }}"><span>{{ array_key_first($d['trend']) }}</span><span>{{ array_key_last($d['trend']) }}</span></div>
        </x-filament::section>
        @if ($d['atRisk']->isNotEmpty())
            <x-filament::section heading="At Risk" description="Forecast to finish below break-even at the current sales speed.">
                <table style="width:100%;font-size:14px;border-collapse:collapse"><tr><th style="{{ $th }}">Competition</th><th style="{{ $th }}">Forecast sold</th><th style="{{ $th }}">Break-even</th><th style="{{ $th }}">Forecast profit</th></tr>
                @foreach ($d['atRisk'] as $r)<tr><td style="{{ $td }}">{{ $r['title'] }}</td><td style="{{ $td }}">{{ number_format($r['forecastSold']) }} ({{ $r['forecastPct'] }}%)</td><td style="{{ $td }}">{{ number_format($r['breakEvenTickets']) }} tickets</td><td style="{{ $td }}">{{ $m($r['forecastProfit']) }}</td></tr>@endforeach
                </table>
            </x-filament::section>
        @endif
    @elseif ($tab === 'competitions')
        <x-filament::section heading="Competitions" description="Live and closed in the last 14 days. Forecasts use the last 24 hours’ sales speed.">
            <div style="overflow-x:auto"><table style="width:100%;font-size:13px;border-collapse:collapse;min-width:980px">
                <tr>@foreach (['Competition', 'Status', 'Sold', 'Revenue', 'Tickets/hour', 'Forecast sold', 'Sell-out', 'Forecast revenue', 'Prize cost', 'Break-even', 'Profit so far', 'Forecast profit'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
                @forelse ($d['competitions'] as $r)
                    <tr style="{{ $r['atRisk'] ? 'background:rgba(239,68,68,.08)' : '' }}">
                        <td style="{{ $td }}">{{ $r['title'] }}</td><td style="{{ $td }}">{{ str_replace('_', ' ', $r['status']) }}</td>
                        <td style="{{ $td }}">{{ number_format($r['sold']) }} / {{ number_format($r['max']) }} ({{ $r['soldPct'] }}%)</td><td style="{{ $td }}">{{ $m($r['revenue']) }}</td>
                        <td style="{{ $td }}">{{ $r['perHour'] }}</td><td style="{{ $td }}">{{ $r['forecastPct'] }}%</td>
                        <td style="{{ $td }}">{{ $r['sellOutAt'] ? $r['sellOutAt']->timezone('Europe/London')->format('j M H:i') : '—' }}</td>
                        <td style="{{ $td }}">{{ $m($r['forecastRevenue']) }}</td><td style="{{ $td }}">{{ $m($r['prizeCost']) }}</td>
                        <td style="{{ $td }}">{{ $r['breakEvenTickets'] !== null ? number_format($r['breakEvenTickets']) : '—' }}</td>
                        <td style="{{ $td }}">{{ $m($r['profitSoFar']) }}</td><td style="{{ $td }}">{{ $m($r['forecastProfit']) }}</td>
                    </tr>
                @empty <tr><td style="{{ $td }}" colspan="12">No competitions yet.</td></tr>
                @endforelse
            </table></div>
            <div style="margin-top:10px"><x-filament::button size="sm" color="gray" tag="a" :href="route('admin.reports.export', ['what' => 'competitions', 'format' => 'xlsx', 'period' => $period])">Export to Excel</x-filament::button></div>
        </x-filament::section>
    @elseif ($tab === 'customers')
        @php($c = $d['customers'])
        <x-filament::section heading="Customers">
            <div style="{{ $k }}">
                @foreach ([['Registered', number_format($c['registered'])], ['New', number_format($c['new'])], ['Active (bought)', number_format($c['active'])], ['Repeat (2+ orders)', number_format($c['repeat'])], ['Inactive 30 days', number_format($c['inactive'])], ['Average order', $m($c['aov'])], ['Lifetime value', $m($c['ltv'])], ['Retention', $pct($c['retentionPct'])], ['Repeat purchase rate', $pct($c['repeatPct'])]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $v }}</div></div>
                @endforeach
            </div>
        </x-filament::section>
        <x-filament::section heading="Cohorts" description="Players by the month they joined, and the share who bought in each month after.">
            <table style="width:100%;font-size:13px;border-collapse:collapse"><tr><th style="{{ $th }}">Joined</th><th style="{{ $th }}">Players</th>@for ($i = 0; $i < 6; $i++)<th style="{{ $th }}">Month {{ $i }}</th>@endfor</tr>
            @foreach ($d['cohorts'] as $row)<tr><td style="{{ $td }}">{{ $row['cohort'] }}</td><td style="{{ $td }}">{{ $row['players'] }}</td>@for ($i = 0; $i < 6; $i++)<td style="{{ $td }}">{{ isset($row['months'][$i]) ? $pct($row['months'][$i]) : '' }}</td>@endfor</tr>@endforeach
            </table>
            <div style="margin-top:10px"><x-filament::button size="sm" color="gray" tag="a" :href="route('admin.reports.export', ['what' => 'segments', 'format' => 'csv', 'period' => $period])">Export customer segments (CSV)</x-filament::button></div>
        </x-filament::section>
    @elseif ($tab === 'marketing')
        @php($mk = $d['marketing'])
        <x-filament::section heading="Marketing" description="Spend comes from Costs (category Marketing, with its channel). Revenue and new customers come from each order’s channel, for visitors who accepted cookies.">
            <div style="{{ $k }}">
                @foreach ([['Spend', $m($mk['spend'])], ['Revenue', $m($mk['revenue'])], ['Return on ad spend', $mk['roas'] !== null ? $mk['roas'].'x' : '—'], ['New customers', number_format($mk['newCustomers'])], ['Cost per new customer', $m($mk['cpa'])]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $v }}</div></div>
                @endforeach
            </div>
            <table style="width:100%;font-size:14px;border-collapse:collapse;margin-top:12px"><tr>@foreach (['Channel', 'Spend', 'Revenue', 'ROAS', 'New customers', 'Cost per new customer'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
            @forelse ($mk['rows'] as $r)<tr><td style="{{ $td }}">{{ $r['channel'] }}</td><td style="{{ $td }}">{{ $m($r['spend']) }}</td><td style="{{ $td }}">{{ $m($r['revenue']) }}</td><td style="{{ $td }}">{{ $r['roas'] !== null ? $r['roas'].'x' : '—' }}</td><td style="{{ $td }}">{{ $r['newCustomers'] }}</td><td style="{{ $td }}">{{ $m($r['cpa']) }}</td></tr>
            @empty <tr><td style="{{ $td }}" colspan="6">No data for this period yet.</td></tr> @endforelse
            </table>
        </x-filament::section>
    @elseif ($tab === 'operations')
        @php($o = $d['operations'])
        <x-filament::section heading="Operations">
            <div style="{{ $k }}">
                @foreach ([['Failed payments', $o['failedPayments']], ['Unpaid orders', $o['pendingOrders']], ['Orders to check', $o['needsAttention']], ['Prizes to send', $o['prizesToSend']], ['Instant wins awarded', $o['instantAwarded']], ['Instant wins left', $o['instantRemaining']], ['Open disputes', $o['openDisputes']], ['Referrals to review', $o['referralsToReview']]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ number_format($v) }}</div></div>
                @endforeach
            </div>
            <h3 style="margin:16px 0 6px;font-weight:600">Unusual activity</h3>
            @if ($o['busyIps']) <table style="font-size:14px;border-collapse:collapse"><tr><th style="{{ $th }}">IP address</th><th style="{{ $th }}">Orders in the last hour</th></tr>@foreach ($o['busyIps'] as $ip => $n)<tr><td style="{{ $td }}">{{ $ip }}</td><td style="{{ $td }}">{{ $n }}</td></tr>@endforeach</table>
            @else <p style="{{ $lab }}">Nothing unusual in the last hour.</p> @endif
        </x-filament::section>
    @else
        <x-filament::section heading="Instant Wins by Game">
            <table style="width:100%;font-size:14px;border-collapse:collapse"><tr>@foreach (['Game', 'Plays', 'Winners', 'Prizes paid', 'Prizes left', 'Revenue', 'Profit'] as $h)<th style="{{ $th }}">{{ $h }}</th>@endforeach</tr>
            @forelse ($d['instant'] as $r)<tr><td style="{{ $td }}">{{ str_replace('_', ' ', $r['game']) }}</td><td style="{{ $td }}">{{ number_format($r['plays']) }}</td><td style="{{ $td }}">{{ number_format($r['winners']) }}</td><td style="{{ $td }}">{{ $m($r['prizesPaid']) }}</td><td style="{{ $td }}">{{ $m($r['prizesLeft']) }}</td><td style="{{ $td }}">{{ $m($r['revenue']) }}</td><td style="{{ $td }}">{{ $m($r['profit']) }}</td></tr>
            @empty <tr><td style="{{ $td }}" colspan="7">No instant wins yet.</td></tr> @endforelse</table>
        </x-filament::section>
        @php($pr = $d['promotions'])
        <x-filament::section heading="Promotions and Bundles" :description="$pr['budgetPct'] ? 'Promotional budget: '.$pr['budgetPct'].'% of revenue'.($pr['overBudget'] ? ' — over budget' : '') : 'Set a promotional budget in Settings to be alerted when it’s passed.'">
            <div style="{{ $k }}">
                @foreach ([['Bundle free tickets', number_format($pr['bundleFreeTickets'])], ['Bonus tickets', number_format($pr['bonusTickets'])], ['Their value at ticket price', $m($pr['bundleAndBonusValue'])], ['Voucher entries value', $m($pr['voucherValue'])], ['Promo code discounts', $m($pr['promoCodeDiscounts'])], ['Bonus credit spent', $m($pr['bonusCreditSpent'])], ['Promotional cost', $m($pr['promoCost'])], ['% of revenue', $pct($pr['promoPctOfRevenue'])]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $v }}</div></div>
                @endforeach
            </div>
        </x-filament::section>
        @php($pg = $d['programmes'])
        <x-filament::section heading="Loyalty, Memberships and Referrals">
            <div style="{{ $k }}">
                @foreach ([['Points issued', number_format($pg['pointsIssued'])], ['Points redeemed', number_format($pg['pointsRedeemed'])], ['Points outstanding', number_format($pg['pointsOutstanding'])], ['Loyalty credit given', $m($pg['loyaltyCreditGiven'])], ['Active members', number_format($pg['activeMembers'])], ['Monthly membership revenue', $m($pg['mrr'])], ['Memberships started / ended', $pg['membershipsStarted'].' / '.$pg['membershipsEnded']], ['Membership credit given', $m($pg['membershipPerksCredit'])], ['Referrals rewarded', number_format($pg['referralsRewarded'])], ['Referral credit given', $m($pg['referralCredit'])], ['Referral free entries', number_format($pg['referralTickets'])]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $v }}</div></div>
                @endforeach
            </div>
        </x-filament::section>
        @php($f = $d['finance'])
        <x-filament::section heading="Finance" description="Upload settlement reports under Reports → Reconciliation to match payments against orders.">
            <div style="{{ $k }}">
                @foreach ([['Taken by card', $m($f['cardTaken'])], ['Settled by the provider', $m($f['settled'])], ['Unmatched settlement lines', number_format($f['unmatched'])], ['Refunds', $m($f['refunds'])], ['Chargebacks', number_format($f['chargebacks'])], ['Chargebacks lost', $m($f['chargebacksLost'])], ['Players’ cash balances', $m($f['walletCash'])], ['Players’ winnings', $m($f['walletWinnings'])], ['Bonus credit outstanding', $m($f['walletBonus'])], ['Prizes to send', number_format($f['prizesToSend'])]] as [$label, $v])
                    <div style="{{ $tile }}"><div style="{{ $lab }}">{{ $label }}</div><div style="{{ $big }}">{{ $v }}</div></div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
