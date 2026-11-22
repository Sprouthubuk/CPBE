<?php

namespace App\Filament\Pages;

use App\Models\SettlementLine;
use App\Services\Settlements;
use App\Support\Roles;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithFileUploads;

/** Payment reconciliation (SOW 2.14): upload the provider's settlement report and see what doesn't match. */
class Reconciliation extends Page
{
    use WithFileUploads;

    public static function canAccess(): bool
    {
        return Roles::allows('finance');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $title = 'Reconciliation';

    protected static ?int $navigationSort = 5;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected string $view = 'filament.pages.reconciliation';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $file = null;

    public function import(): void
    {
        $this->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt']]);
        try {
            $r = app(Settlements::class)->import((string) file_get_contents($this->file->getRealPath()));
            \App\Models\AdminAction::record(auth()->user(), 'settlements.imported', $r['batch'], null, $r);
            Notification::make()->success()->title("{$r['lines']} lines: {$r['matched']} matched")
                ->body(($r['amount'] ?? 0).' with a different amount, '.($r['unmatched'] ?? 0).' with no order.')->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('Not imported')->body($e->getMessage())->send();
        }
        $this->file = null;
    }

    public function problems()
    {
        return SettlementLine::query()->where('match', '!=', 'matched')->latest('id')->limit(100)->get();
    }

    public function unsettled()
    {
        return app(Settlements::class)->unsettled(now()->subDays(60), now()->subDays(3));
    }
}
