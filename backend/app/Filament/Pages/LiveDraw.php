<?php

namespace App\Filament\Pages;

use App\Models\AdminAction;
use App\Models\Competition;
use App\Services\CompetitionState;
use App\Services\EndPrizeRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Sprouthub\Verity\Facades\Verity;
use Sprouthub\Verity\Services\BeaconNotReady;

/**
 * The live draw console (SOW 2.4): competitions with a draw that have closed, a "Draw now" button and a
 * full-screen reveal to show on the live stream. The winner always comes from Verity (drand beacon), never
 * from this page: it only asks Verity to draw now instead of waiting for verity:process-due.
 */
class LiveDraw extends Page implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('competitions');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static ?string $title = 'Live Draw';

    protected static ?int $navigationSort = 8;

    protected static string|\UnitEnum|null $navigationGroup = 'Competitions';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Closed draws appear here. "Draw now" asks Verity for the result as soon as the committed drand round is out (usually within a minute of closing); otherwise Verity draws it on its own. Open the reveal full screen on the stream.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $state = app(CompetitionState::class);

        return $table
            ->query(Competition::query()->published()->where(fn ($query) => $query->where('type', 'draw')->orWhereNotNull('end_prize_name'))
                ->where('closes_at', '>', now()->subDays(30))->orderBy('closes_at'))
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('closes_at')->label('Closes')->dateTime('D j M, H:i', 'Europe/London'),
                TextColumn::make('status')->state(fn (Competition $c) => $state->status($c))->badge()
                    ->color(fn ($state) => match ($state) { 'drawn' => 'success', 'closed' => 'warning', default => 'gray' }),
            ])
            ->recordActions([
                Action::make('draw')->label('Draw now')->icon(Heroicon::OutlinedSparkles)->color('primary')
                    ->visible(fn (Competition $c) => $state->status($c) === 'closed')
                    ->requiresConfirmation()->modalDescription('Verity draws the winner from the drand round committed at publish. This can’t be undone.')
                    ->action(fn (Competition $c) => $this->drawNow($c)),
                Action::make('reveal')->label('Open reveal')->icon(Heroicon::OutlinedPlayCircle)
                    ->visible(fn (Competition $c) => $state->status($c) === 'drawn')
                    ->url(fn (Competition $c) => route('admin.live-draw.reveal', $c), shouldOpenInNewTab: true),
            ]);
    }

    public function drawNow(Competition $c): void
    {
        try {
            $v = Verity::draw($c->verity_ref);
        } catch (BeaconNotReady) {
            Notification::make()->warning()->title('Not yet')->body('The drand round Verity committed to isn’t out yet. Try again in a few seconds.')->send();

            return;
        } catch (\Sprouthub\Verity\Engine\VerityException $e) {
            Notification::make()->danger()->title('Not drawn')->body($e->getMessage())->send();

            return;
        }
        app(EndPrizeRules::class)->enforce($v);
        AdminAction::record(auth()->user(), 'competition.drawn_live', (string) $c->id, null);
        Notification::make()->success()->title('Drawn')->body('Open the reveal to show the winner.')->send();
    }
}
