<?php

namespace App\Filament\Pages;

use App\Models\AdminAction;
use App\Models\HarmFlag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Voluntary Code 1.6 / 1.7 (amendment 11): signs of possible harm, and the step a person took for each. Putting an
 * account on hold stops all entries at once (PlayCheck); a hold is released from Players.
 */
class PlayerProtection extends Page implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('players');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $title = 'Player Protection';

    protected static ?string $slug = 'player-protection';

    protected static string|\UnitEnum|null $navigationGroup = 'Players & Money';

    public static function getNavigationBadge(): ?string
    {
        $n = HarmFlag::query()->whereNull('reviewed_at')->count();

        return $n > 0 ? (string) $n : null;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Flags from the Voluntary Code’s harm indicators: limits reached or raised, high spending, many top-ups or cards, spending again straight after a break. Review each one and record what you did. Thresholds are in Settings → Player Protection.'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->query(HarmFlag::query()->with('user')->latest())
            ->columns([
                TextColumn::make('created_at')->label('Raised')->dateTime('j M Y, H:i', 'Europe/London'),
                TextColumn::make('user.email')->label('Player')->searchable(),
                TextColumn::make('indicator')->formatStateUsing(fn ($state) => HarmFlag::INDICATORS[$state] ?? $state)->badge()->color('warning'),
                TextColumn::make('detail')->wrap(),
                TextColumn::make('outcome')->placeholder('Not reviewed')->wrap(),
            ])
            ->filters([TernaryFilter::make('reviewed_at')->label('Reviewed')->nullable()->default(false)])
            ->recordActions([
                Action::make('review')->label('Record step')->icon(Heroicon::OutlinedCheck)->visible(fn (HarmFlag $f) => $f->reviewed_at === null)
                    ->schema([
                        Select::make('step')->options(['none' => 'No action needed', 'contacted' => 'Contacted the player', 'hold' => 'Put the account on hold'])->required(),
                        Textarea::make('note')->required()->maxLength(250),
                    ])
                    ->action(function (HarmFlag $f, array $data) {
                        if ($data['step'] === 'hold' && ! $f->user->on_hold_at) {
                            $f->user->forceFill(['on_hold_at' => now(), 'on_hold_reason' => 'Player protection: '.$data['note']])->save();
                        }
                        $outcome = ['none' => 'No action', 'contacted' => 'Contacted', 'hold' => 'Put on hold'][$data['step']].' · '.$data['note'];
                        $f->forceFill(['reviewed_at' => now(), 'reviewed_by' => auth()->id(), 'outcome' => mb_substr($outcome, 0, 300)])->save();
                        AdminAction::record(auth()->user(), 'player.protection_'.$data['step'], (string) $f->user_id, $data['note'], ['flag' => $f->id, 'indicator' => $f->indicator]);
                        Notification::make()->success()->title('Recorded')->send();
                    }),
            ]);
    }
}
