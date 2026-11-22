<?php

namespace App\Filament\Resources\ReportsAdmin;

use App\Models\ReportSchedule;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Summary reports emailed on a schedule (SOW 2.14). */
class ReportScheduleResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('reports');
    }

    protected static ?string $model = ReportSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Scheduled Reports';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required()->maxLength(190),
            Select::make('frequency')->options(ReportSchedule::FREQUENCIES)->required(),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('email'), TextColumn::make('frequency')->formatStateUsing(fn ($state) => ReportSchedule::FREQUENCIES[$state] ?? $state),
            IconColumn::make('active')->boolean(), TextColumn::make('last_sent_at')->label('Last sent')->dateTime('j M Y, H:i', 'Europe/London')->placeholder('Not yet'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReportSchedules::route('/'), 'create' => Pages\CreateReportSchedule::route('/create'), 'edit' => Pages\EditReportSchedule::route('/{record}/edit')];
    }
}
