<?php

namespace App\Filament\Resources\PromoCodes;

use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Models\PromoCode;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PromoCodeResource extends Resource
{
    /** Admin roles (App\Support\Roles): the marketing area. */
    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('marketing');
    }

    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 7;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->alphaDash()->maxLength(32)->unique(ignoreRecord: true),
            Select::make('discount_type')->label('Discount')->options(PromoCode::TYPES)->default('percent')->required()->live(),
            TextInput::make('percent_off')->label('% off')->integer()->minValue(1)->maxValue(PromoCode::MAX_PERCENT)
                ->required(fn (Get $get) => $get('discount_type') !== 'fixed')->visible(fn (Get $get) => $get('discount_type') !== 'fixed')
                ->helperText('At most '.PromoCode::MAX_PERCENT.'%: a code never makes an order free.'),
            TextInput::make('amount_off_pence')->label('Amount off (£)')->numeric()->minValue(0.01)->step(0.01)
                ->required(fn (Get $get) => $get('discount_type') === 'fixed')->visible(fn (Get $get) => $get('discount_type') === 'fixed')
                ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 100, 2, '.', ''))
                ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : (int) round(((float) $state) * 100))
                ->helperText('Never more than half of what the code applies to.'),
            TextInput::make('min_spend_pence')->label('Minimum spend (£)')->numeric()->minValue(0)->step(0.01)->default(0)
                ->formatStateUsing(fn ($state) => number_format(((int) $state) / 100, 2, '.', ''))
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                ->helperText('The basket total before the discount. 0 for no minimum.'),
            TextInput::make('max_uses')->label('Total uses')->integer()->minValue(1)->helperText('Empty for no limit.'),
            TextInput::make('max_uses_per_player')->label('Uses per player')->integer()->minValue(1)->helperText('Empty for no limit.'),
            Toggle::make('include_bundles')->label('Also applies to bundles')->default(false)
                ->helperText('Off: single tickets only (bundles are already discounted).'),
            DateTimePicker::make('starts_at')->label('Starts')->timezone('Europe/London')->seconds(false)->helperText('Empty to start now.'),
            DateTimePicker::make('expires_at')->label('Expires')->timezone('Europe/London')->seconds(false)->after('starts_at'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('discount')->state(fn (PromoCode $p) => $p->label()),
            TextColumn::make('min_spend_pence')->label('Min spend')->formatStateUsing(fn ($state) => $state ? '£'.number_format($state / 100, 2) : '—'),
            TextColumn::make('uses')->formatStateUsing(fn ($state, PromoCode $p) => $state.($p->max_uses ? ' / '.$p->max_uses : '')),
            TextColumn::make('expires_at')->dateTime('j M Y, H:i', 'Europe/London')->placeholder('Never'),
            IconColumn::make('active')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPromoCodes::route('/'), 'create' => CreatePromoCode::route('/create'), 'edit' => EditPromoCode::route('/{record}/edit')];
    }
}
