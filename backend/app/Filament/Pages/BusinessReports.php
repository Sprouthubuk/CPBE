<?php

namespace App\Filament\Pages;

use App\Services\Reports;
use App\Support\Roles;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The executive and operational dashboard (SOW 2.14): one screen with the whole business, by period, with
 * tabs that drill into competitions, customers, marketing, operations and detailed reports. Exports to
 * Excel, CSV and a printable page (save as PDF).
 */
class BusinessReports extends Page
{
    public static function canAccess(): bool
    {
        return Roles::allows('reports');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?string $title = 'Business Dashboard';

    protected static ?string $slug = 'business';

    protected static ?int $navigationSort = 1;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.business-reports';

    public string $period = 'month';

    public string $tab = 'overview';

    public string $trend = 'day';

    public function data(): array
    {
        $r = app(Reports::class);
        $period = array_key_exists($this->period, Reports::PERIODS) ? $this->period : 'month';
        [$from, $to, $pf, $pt] = $r->range($period);

        return match ($this->tab) {
            'competitions' => ['competitions' => $r->competitions()],
            'customers' => ['customers' => $r->customers($from, $to, $pf, $pt), 'cohorts' => $r->cohorts()],
            'marketing' => ['marketing' => $r->marketing($from, $to)],
            'operations' => ['operations' => $r->operations($from, $to)],
            'detail' => ['instant' => $r->instantWins($from, $to), 'promotions' => $r->promotions($from, $to), 'programmes' => $r->programmes($from, $to), 'finance' => $r->finance($from, $to)],
            default => ['company' => $r->company($from, $to), 'previous' => $r->company($pf, $pt), 'headline' => $r->headline(), 'trend' => $r->trend($this->trend),
                'atRisk' => $r->competitions()->where('atRisk', true)->values()],
        };
    }
}
