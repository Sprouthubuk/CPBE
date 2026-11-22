<?php

namespace App\Filament\Resources\ReportsAdmin;

use App\Filament\Support\Money;
use App\Models\Competition;
use App\Models\CostEntry;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Costs the platform can't capture by itself (SOW 2.14): ad spend, prizes bought, running costs. */
class CostEntryResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('reports');
    }

    protected static ?string $model = CostEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Costs';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->required()->default(now('Europe/London')->toDateString()),
            Select::make('category')->options(CostEntry::CATEGORIES)->required()->live(),
            Money::input('amount_pence', 'Amount')->required(),
            Select::make('basis')->options(CostEntry::BASES)->default('actual')->required(),
            TextInput::make('channel')->label('Channel (e.g. meta, google, tiktok)')->maxLength(40)->alphaDash()
                ->visible(fn (Get $get) => $get('category') === 'marketing'),
            Select::make('competition_id')->label('Competition (optional)')->options(fn () => Competition::query()->orderByDesc('created_at')->limit(200)->pluck('title', 'id')->all())->searchable(),
            TextInput::make('note')->maxLength(200)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            TextColumn::make('date')->date('j M Y'),
            TextColumn::make('category')->formatStateUsing(fn ($state) => CostEntry::CATEGORIES[$state] ?? $state),
            TextColumn::make('channel')->placeholder('—'),
            TextColumn::make('amount_pence')->label('Amount')->formatStateUsing(fn ($state) => Money::show($state)),
            TextColumn::make('basis')->badge()->formatStateUsing(fn ($state) => CostEntry::BASES[$state] ?? $state),
            TextColumn::make('note')->limit(40)->placeholder('—'),
        ])->filters([SelectFilter::make('category')->options(CostEntry::CATEGORIES), SelectFilter::make('basis')->options(CostEntry::BASES)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCostEntries::route('/'), 'create' => Pages\CreateCostEntry::route('/create'), 'edit' => Pages\EditCostEntry::route('/{record}/edit')];
    }
}
