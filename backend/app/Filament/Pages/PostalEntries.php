<?php

namespace App\Filament\Pages;

use App\Models\Competition;
use App\Models\PostalEntry;
use App\Models\User;
use App\Services\Postal;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/** Log a free postal entry: one envelope, one entry. Every envelope is recorded, entered or refused. */
class PostalEntries extends Page implements HasTable
{
    /** Admin roles (App\Support\Roles): the postal area. */
    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('postal');
    }

    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $title = 'Postal Entries';

    protected static ?int $navigationSort = 9;

    protected static string|\UnitEnum|null $navigationGroup = 'Competitions';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['received_at' => now('Europe/London')->startOfHour()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(3)->components([
            Select::make('competition_id')->label('Competition')->required()->searchable()
                ->options(fn () => Competition::query()->published()->where('closes_at', '>', now()->subDays(14))->orderBy('closes_at')->pluck('title', 'id')),
            TextInput::make('email')->label('Account email on the entry')->email()->required(),
            DateTimePicker::make('received_at')->label('Envelope received (UK time)')->timezone('Europe/London')->seconds(false)->required(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Log an envelope')->components([
                Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('log')
                    ->footer([Actions::make([Action::make('log')->label('Log entry')->submit('log')])]),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    public function log(Postal $postal): void
    {
        $d = $this->form->getState();
        $c = Competition::query()->findOrFail($d['competition_id']);
        $p = $postal->log($c, $d['email'], Carbon::parse($d['received_at']), auth()->user());
        $p->status === 'entered'
            ? Notification::make()->success()->title('Entered')->send()
            : Notification::make()->warning()->title('Recorded as refused')->body($p->reason)->send();
        $this->form->fill(['competition_id' => $d['competition_id'], 'received_at' => $d['received_at']]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label('Import envelopes (CSV)')->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription('One row per envelope, with the columns email and received_at (UK time, e.g. 21/11/2026 14:30 or 2026-11-21 14:30). Each row is checked and entered exactly like one logged by hand.')
                ->schema([
                    Select::make('competition_id')->label('Competition')->required()->searchable()
                        ->options(fn () => Competition::query()->published()->where('closes_at', '>', now()->subDays(14))->orderBy('closes_at')->pluck('title', 'id')),
                    \Filament\Forms\Components\FileUpload::make('file')->label('CSV file')->disk('local')->directory('imports')->visibility('private')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])->maxSize(2048)->required(),
                ])
                ->action(function (array $data, Postal $postal) {
                    $disk = \Illuminate\Support\Facades\Storage::disk('local');
                    $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    try {
                        $c = Competition::query()->findOrFail($data['competition_id']);
                        $r = $postal->importCsv($c, (string) $disk->get($path), auth()->user());
                        \App\Models\AdminAction::record(auth()->user(), 'postal.import', (string) $c->id, null, ['entered' => $r['entered'], 'refused' => $r['refused']]);
                        $why = collect($r['rows'])->where('status', '!=', 'entered')->take(8)->map(fn ($x) => "Row {$x['line']} ({$x['email']}): {$x['reason']}")->implode("\n");
                        Notification::make()->success()->title("{$r['entered']} entered, {$r['refused']} refused")->body($why ?: null)->persistent()->send();
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title('Not imported')->body($e->getMessage())->send();
                    } finally {
                        $disk->delete($path);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->query(PostalEntry::query()->latest())->columns([
            TextColumn::make('received_at')->dateTime('j M Y, H:i', 'Europe/London'),
            TextColumn::make('competition_id')->label('Competition')->formatStateUsing(fn ($state) => Competition::query()->find($state)?->title),
            TextColumn::make('user_id')->label('Player')->formatStateUsing(fn ($state) => User::query()->find($state)?->email),
            TextColumn::make('status')->badge()->color(fn ($state) => $state === 'entered' ? 'success' : 'danger'),
            TextColumn::make('reason')->wrap(),
        ]);
    }
}
