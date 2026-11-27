<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\Setting;
use App\Services\ProfitPlanner as Planner;
use App\Support\Roles;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** The competition profit planner (SOW 2.14): up to three options side by side, in pounds. */
class ProfitPlanner extends Page
{
    public static function canAccess(): bool
    {
        return Roles::allows('reports') || Roles::allows('competitions');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $title = 'Profit Planner';

    protected static ?int $navigationSort = 2;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.profit-planner';

    /** @var list<array<string, mixed>> inputs in pounds and percent, as typed */
    public array $options = [];

    public ?string $load = null;

    public function mount(): void
    {
        $base = self::toForm(Planner::DEFAULTS + ['feeBp' => Setting::int('payment_fee_bp') ?: 200, 'feeFixed' => Setting::int('payment_fee_fixed_pence') ?: 20]);
        $this->options = [['name' => 'Option A'] + $base, ['name' => 'Option B', 'ticketPrice' => '1.49', 'maxTickets' => 1500] + $base];
    }

    public function addOption(): void
    {
        if (count($this->options) < 3) {
            $this->options[] = ['name' => 'Option '.chr(65 + count($this->options))] + end($this->options);
        }
    }

    public function removeOption(int $i): void
    {
        if (count($this->options) > 1) {
            array_splice($this->options, $i, 1);
        }
    }

    /** What-if: start from an existing competition's real numbers. */
    public function updatedLoad(?string $id): void
    {
        $c = $id ? Competition::query()->with('prizes')->find($id) : null;
        if (! $c) {
            return;
        }
        $prize = $c->isInstant() ? (int) $c->prizes->sum(fn ($p) => $p->value_pence * $p->quantity) : (int) ($c->prize_cost_pence ?? $c->end_prize_value_pence ?? 0);
        $this->options[0] = array_merge($this->options[0], ['name' => $c->title, 'prizeCost' => number_format($prize / 100, 2, '.', ''),
            'ticketPrice' => number_format($c->price_pence / 100, 2, '.', ''), 'maxTickets' => $c->max_tickets]);
    }

    /** @return list<array<string, mixed>> */
    public function results(): array
    {
        return array_map(fn ($o) => ['name' => $o['name'] ?? 'Option'] + Planner::model(self::toModel($o)), $this->options);
    }

    public function competitions(): array
    {
        return Competition::query()->orderByDesc('created_at')->limit(100)->pluck('title', 'id')->all();
    }

    private static function toForm(array $m): array
    {
        return ['prizeCost' => number_format($m['prizeCost'] / 100, 2, '.', ''), 'ticketPrice' => number_format($m['ticketPrice'] / 100, 2, '.', ''),
            'maxTickets' => $m['maxTickets'], 'freeSharePct' => $m['freeSharePct'], 'promoSharePct' => $m['promoSharePct'], 'discountPct' => $m['discountPct'],
            'marketing' => number_format($m['marketing'] / 100, 2, '.', ''), 'vatPct' => $m['vatPct'], 'feePct' => number_format($m['feeBp'] / 100, 2, '.', ''),
            'feeFixed' => number_format($m['feeFixed'] / 100, 2, '.', ''), 'avgOrder' => number_format($m['avgOrder'] / 100, 2, '.', ''),
            'guaranteedPrizes' => number_format($m['guaranteedPrizes'] / 100, 2, '.', ''), 'cashAltValue' => number_format($m['cashAltValue'] / 100, 2, '.', ''),
            'cashAltSharePct' => $m['cashAltSharePct']];
    }

    private static function toModel(array $f): array
    {
        $p = fn ($v) => (int) round(((float) str_replace(['£', ','], '', (string) $v)) * 100);

        return ['prizeCost' => $p($f['prizeCost'] ?? 0), 'ticketPrice' => $p($f['ticketPrice'] ?? 0), 'maxTickets' => (int) ($f['maxTickets'] ?? 0),
            'freeSharePct' => (int) ($f['freeSharePct'] ?? 0), 'promoSharePct' => (int) ($f['promoSharePct'] ?? 0), 'discountPct' => (int) ($f['discountPct'] ?? 0),
            'marketing' => $p($f['marketing'] ?? 0), 'vatPct' => (int) ($f['vatPct'] ?? 0), 'feeBp' => (int) round(((float) ($f['feePct'] ?? 0)) * 100),
            'feeFixed' => $p($f['feeFixed'] ?? 0), 'avgOrder' => $p($f['avgOrder'] ?? 0),
            'guaranteedPrizes' => $p($f['guaranteedPrizes'] ?? 0), 'cashAltValue' => $p($f['cashAltValue'] ?? 0), 'cashAltSharePct' => (int) ($f['cashAltSharePct'] ?? 0)];
    }
}
