<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// A player's ID document, for admins only (the admin's ID Checks page links here). Never public.
Route::get('/admin/id-checks/{check}/{which}', function (\App\Models\IdCheck $check, string $which) {
    $user = request()->user();
    // An admin who has finished two-step sign-in (the admin makes every admin set it up before anything else).
    abort_unless($user && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) && $user->getAppAuthenticationSecret(), 403);
    abort_unless(in_array($which, ['photo', 'address'], true), 404);
    $path = $which === 'photo' ? $check->photo_path : $check->address_path;
    abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($path), 404);
    \App\Models\AdminAction::record($user, 'id_check.viewed', (string) $check->user_id, null, ['document' => $which]);

    return \Illuminate\Support\Facades\Storage::disk('local')->response($path, null, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
})->name('admin.id-check-file');

// A dispute's printable evidence pack (SOW 2.15), for admins with the finance area only.
Route::get('/admin/evidence/{order}', function (string $order) {
    $user = request()->user();
    abort_unless($user && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) && $user->getAppAuthenticationSecret()
        && \App\Support\Roles::can($user, 'finance'), 403);
    $o = \App\Models\Order::query()->where('number', $order)->firstOrFail();
    \App\Models\AdminAction::record($user, 'dispute.evidence_viewed', $o->number, null);

    return response()->view('admin.evidence', ['order' => $o, 'e' => app(\App\Services\Disputes::class)->evidence($o),
        'disputes' => \App\Models\Dispute::query()->where('order_id', $o->id)->get()], 200, ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
})->name('admin.evidence');

// Report exports (SOW 2.14): Excel / CSV downloads and a printable page (save as PDF), reports area only.
Route::middleware('web')->group(function () {
    $allowed = function () {
        $user = request()->user();
        abort_unless($user && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) && $user->getAppAuthenticationSecret()
            && \App\Support\Roles::can($user, 'reports'), 403);

        return $user;
    };
    Route::get('/admin/reports/export/{what}.{format}', function (string $what, string $format) use ($allowed) {
        $user = $allowed();
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $r = app(\App\Services\Reports::class);
        $period = array_key_exists((string) request('period'), \App\Services\Reports::PERIODS) ? request('period') : 'month';
        [$from, $to] = $r->range($period);
        $rows = match ($what) {
            'orders' => $r->orderRows($from, $to),
            'competitions' => [['Competition', 'Type', 'Status', 'Sold', 'Max', 'Sold %', 'Revenue £', 'Tickets/hour', 'Forecast sold', 'Forecast revenue £', 'Prize cost £', 'Break-even tickets', 'Profit so far £', 'Forecast profit £'],
                ...$r->competitions()->map(fn ($c) => [$c['title'], $c['type'], $c['status'], $c['sold'], $c['max'], $c['soldPct'], $c['revenue'] / 100, $c['perHour'],
                    $c['forecastSold'], $c['forecastRevenue'] / 100, $c['prizeCost'] / 100, $c['breakEvenTickets'], $c['profitSoFar'] / 100, $c['forecastProfit'] / 100])->all()],
            'segments' => app(\App\Services\Segments::class)->rows((string) request('segment', 'all')),
            default => abort(404),
        };
        \App\Models\AdminAction::record($user, 'report.exported', $what, null, ['format' => $format, 'period' => $period]);

        return \App\Support\Spreadsheet::download($rows, "{$what}-{$period}-".now('Europe/London')->format('Y-m-d'), $format);
    })->name('admin.reports.export');
    Route::get('/admin/reports/print', function () use ($allowed) {
        $allowed();
        $r = app(\App\Services\Reports::class);
        $period = array_key_exists((string) request('period'), \App\Services\Reports::PERIODS) ? request('period') : 'month';
        [$from, $to, $pf, $pt] = $r->range($period);

        return response()->view('admin.report-print', ['period' => $period, 'company' => $r->company($from, $to), 'customers' => $r->customers($from, $to, $pf, $pt),
            'competitions' => $r->competitions(), 'marketing' => $r->marketing($from, $to), 'headline' => $r->headline()], 200, ['Cache-Control' => 'no-store, private']);
    })->name('admin.reports.print');
});

// The live draw reveal (SOW 2.4): a full-screen page to show on the stream once Verity has drawn.
Route::middleware('web')->get('/admin/live-draw/{competition}/reveal', function (\App\Models\Competition $competition) {
    $user = request()->user();
    abort_unless($user && $user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')) && $user->getAppAuthenticationSecret()
        && \App\Support\Roles::can($user, 'competitions'), 403);
    abort_unless($competition->verity_ref !== null, 404);
    $v = \Sprouthub\Verity\Models\Competition::query()->where('ref', $competition->verity_ref)->firstOrFail();
    $winners = \Sprouthub\Verity\Models\Winner::query()->where('competition_id', $v->id)->where('type', 'main')->where('status', 'active')
        ->with('entry')->orderBy('id')->get()->map(function ($w) {
            $id = \App\Services\Fulfilment::userIdFromOwner((string) $w->owner_ref);
            $u = $id ? \App\Models\User::query()->whereNull('closed_at')->find($id) : null;

            return ['name' => $u?->publicName() ?? 'Postal entrant', 'prize' => $w->prize_name, 'ticket' => $w->entry ? (int) $w->entry->number : null];
        });

    return response()->view('admin.live-draw', ['c' => $competition, 'winners' => $winners, 'ref' => $v->ref], 200,
        ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
})->name('admin.live-draw.reveal');
