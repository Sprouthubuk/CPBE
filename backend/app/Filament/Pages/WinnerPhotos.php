<?php

namespace App\Filament\Pages;

use App\Models\AdminAction;
use App\Models\WinnerMedia;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Sprouthub\Verity\Models\Winner;

/** Photos and draw videos for draw winners (SOW 2.9), shown on /winners. Winners themselves only come from Verity. */
class WinnerPhotos extends Page implements HasTable
{
    use InteractsWithTable;

    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('competitions');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $title = 'Winner Photos';

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = 'Competitions';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table->query(Winner::query()->where('type', 'main')->where('status', 'active')->with('competition')->orderByDesc('id'))
            ->columns([
                TextColumn::make('prize_name')->label('Prize')->wrap(),
                TextColumn::make('competition.title')->label('Competition')->wrap(),
                TextColumn::make('created_at')->label('Won')->dateTime('j M Y', 'Europe/London'),
                IconColumn::make('photo')->boolean()->state(fn (Winner $w) => WinnerMedia::query()->where('verity_winner_id', $w->id)->whereNotNull('photo_path')->where('consent', true)->exists()),
            ])
            ->recordActions([
                Action::make('media')->label('Photo & video')->icon(Heroicon::OutlinedPhoto)
                    ->fillForm(fn (Winner $w) => WinnerMedia::query()->firstWhere('verity_winner_id', $w->id)?->only(['photo_path', 'video_url', 'consent']) ?? [])
                    ->schema([
                        FileUpload::make('photo_path')->label('Photo')->image()->disk('public')->directory('winners')->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(4096)->imageEditor(),
                        TextInput::make('video_url')->label('Draw video link (https)')->url()->startsWith(['https://'])->maxLength(300),
                        Toggle::make('consent')->label('The winner agreed to their photo being shown')->default(false),
                    ])
                    ->action(function (Winner $w, array $data) {
                        WinnerMedia::query()->updateOrCreate(['verity_winner_id' => $w->id], [
                            'photo_path' => $data['photo_path'] ?? null, 'video_url' => $data['video_url'] ?? null, 'consent' => (bool) ($data['consent'] ?? false)]);
                        AdminAction::record(auth()->user(), 'winner.media', (string) $w->id, null, ['photo' => ! empty($data['photo_path']), 'consent' => (bool) ($data['consent'] ?? false)]);
                        Notification::make()->success()->title('Saved')->send();
                    }),
            ]);
    }
}
