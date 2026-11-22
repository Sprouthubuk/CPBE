<?php

namespace App\Filament\Resources\Experiments;

use App\Filament\Support\Money;
use App\Models\Experiment;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A/B tests (SOW 2.18): pick a placement, give each variant its wording and a weight; results show views,
 * conversions, conversion rate and revenue per variant. Only consenting visitors and signed-in players take part.
 */
class ExperimentResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing');
    }

    protected static ?string $model = Experiment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'A/B Tests';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80),
            Select::make('key')->label('Placement')->options(Experiment::PLACEMENTS)->required()->unique(ignoreRecord: true),
            Toggle::make('active')->label('Running')->default(false),
            Repeater::make('variants')->columnSpanFull()->columns(3)->minItems(2)->maxItems(4)->defaultItems(2)
                ->components([
                    TextInput::make('key')->label('Variant id')->required()->alphaDash()->maxLength(30)->helperText('e.g. control, b'),
                    TextInput::make('weight')->integer()->minValue(0)->maxValue(100)->default(50)->required(),
                    TextInput::make('text')->label('Wording (empty: the page as it is)')->maxLength(120),
                ]),
        ]);
    }

    public static function results(Experiment $e): string
    {
        $rows = $e->assignments()->selectRaw('variant, COUNT(seen_at) seen, COUNT(converted_at) converted, COALESCE(SUM(revenue_pence), 0) revenue')
            ->groupBy('variant')->get();

        return $rows->map(fn ($r) => "{$r->variant}: {$r->seen} saw, {$r->converted} bought ("
            .($r->seen ? round($r->converted * 100 / $r->seen, 1) : 0).'%), '.Money::show((int) $r->revenue))->implode(' · ') ?: 'No visitors yet';
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('key')->label('Placement')->formatStateUsing(fn ($state) => Experiment::PLACEMENTS[$state] ?? $state),
            IconColumn::make('active')->boolean()->label('Running'),
            TextColumn::make('results')->state(fn (Experiment $e) => self::results($e))->wrap(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListExperiments::route('/'), 'create' => Pages\CreateExperiment::route('/create'), 'edit' => Pages\EditExperiment::route('/{record}/edit')];
    }
}
