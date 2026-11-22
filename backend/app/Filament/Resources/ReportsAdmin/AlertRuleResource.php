<?php

namespace App\Filament\Resources\ReportsAdmin;

use App\Models\AlertRule;
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

/** Configurable alerts (SOW 2.14): checked every hour, emailed to the admins at most once a day per rule. */
class AlertRuleResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('reports');
    }

    protected static ?string $model = AlertRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Alerts';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('kind')->label('Alert when')->options(AlertRule::KINDS)->required()->columnSpanFull(),
            TextInput::make('threshold')->integer()->minValue(1)->required(),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('kind')->label('Alert when')->formatStateUsing(fn ($state) => AlertRule::KINDS[$state] ?? $state)->wrap(),
            TextColumn::make('threshold'),
            IconColumn::make('active')->boolean(),
            TextColumn::make('last_fired_at')->label('Last sent')->dateTime('j M Y, H:i', 'Europe/London')->placeholder('Never'),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAlertRules::route('/'), 'create' => Pages\CreateAlertRule::route('/create'), 'edit' => Pages\EditAlertRule::route('/{record}/edit')];
    }
}
