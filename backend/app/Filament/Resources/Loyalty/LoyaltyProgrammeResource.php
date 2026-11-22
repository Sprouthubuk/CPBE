<?php

namespace App\Filament\Resources\Loyalty;

use App\Filament\Resources\Loyalty\Pages\CreateLoyaltyProgramme;
use App\Filament\Resources\Loyalty\Pages\EditLoyaltyProgramme;
use App\Filament\Resources\Loyalty\Pages\ListLoyaltyProgrammes;
use App\Filament\Support\Money;
use App\Models\Competition;
use App\Models\LoyaltyBonusEvent;
use App\Models\LoyaltyProgramme;
use App\Models\LoyaltyReward;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Loyalty programmes (SOW 2.11): earn rate, expiry, tiers, bonus events and rewards, each switched on or off. */
class LoyaltyProgrammeResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing');
    }

    protected static ?string $model = LoyaltyProgramme::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Loyalty Programmes';

    protected static ?int $navigationSort = 8;

    private static function competitions(): array
    {
        return Competition::query()->orderByDesc('created_at')->limit(300)->pluck('title', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Programme')->columns(2)->columnSpanFull()->components([
                TextInput::make('name')->required()->maxLength(80),
                Toggle::make('active')->label('Switched on')->default(false)->helperText('Players see it and earn points only while it’s on.'),
                Textarea::make('description')->maxLength(500)->columnSpanFull(),
                TextInput::make('points_per_pound')->label('Points per £1 spent')->integer()->minValue(0)->maxValue(10000)->default(1)->required()
                    ->helperText('On real money only: card, cash balance and winnings, never bonus credit.'),
                TextInput::make('expiry_days')->label('Points expire after (days)')->integer()->minValue(1)->maxValue(3650)->helperText('Empty: points never expire.'),
            ]),
            Repeater::make('tiers')->relationship()->label('Tiers')->columnSpanFull()->columns(4)->defaultItems(0)
                ->orderColumn(false)->addActionLabel('Add a tier')
                ->components([
                    TextInput::make('name')->required()->maxLength(60),
                    TextInput::make('threshold_points')->label('From lifetime points')->integer()->minValue(0)->required(),
                    TextInput::make('multiplier_pct')->label('Earn rate %')->integer()->minValue(100)->maxValue(1000)->default(100)->required()->helperText('150 = 1.5x points'),
                    Textarea::make('benefits')->maxLength(300)->rows(2),
                ]),
            Repeater::make('bonusEvents')->relationship()->label('Bonus points')->columnSpanFull()->columns(3)->defaultItems(0)
                ->addActionLabel('Add a bonus')
                ->components([
                    Select::make('kind')->options(LoyaltyBonusEvent::KINDS)->required()->live(),
                    TextInput::make('points')->label('Bonus points')->integer()->minValue(0)->default(0)
                        ->visible(fn (Get $get) => $get('kind') !== 'competition'),
                    Select::make('competition_id')->label('Competition')->options(fn () => self::competitions())->searchable()
                        ->visible(fn (Get $get) => $get('kind') === 'competition')->required(fn (Get $get) => $get('kind') === 'competition'),
                    TextInput::make('multiplier_pct')->label('Earn rate % on it')->integer()->minValue(100)->maxValue(1000)->default(200)
                        ->visible(fn (Get $get) => $get('kind') === 'competition')->helperText('200 = double points'),
                    DateTimePicker::make('starts_at')->timezone('Europe/London')->seconds(false),
                    DateTimePicker::make('ends_at')->timezone('Europe/London')->seconds(false),
                    Toggle::make('active')->default(true),
                ]),
            Repeater::make('rewards')->relationship()->label('Rewards')->columnSpanFull()->columns(3)->defaultItems(0)
                ->addActionLabel('Add a reward')
                ->components([
                    TextInput::make('name')->required()->maxLength(80),
                    Select::make('type')->options(LoyaltyReward::TYPES)->required()->live(),
                    TextInput::make('points_cost')->label('Points')->integer()->minValue(1)->required(),
                    Money::input('credit_pence', 'Site credit')->visible(fn (Get $get) => $get('type') === 'site_credit')
                        ->required(fn (Get $get) => $get('type') === 'site_credit'),
                    TextInput::make('tickets')->integer()->minValue(1)->maxValue(1000)
                        ->visible(fn (Get $get) => in_array($get('type'), ['free_entries', 'bonus_entries'], true))
                        ->required(fn (Get $get) => in_array($get('type'), ['free_entries', 'bonus_entries'], true)),
                    Select::make('competition_id')->label('Draw')->options(fn () => self::competitions())->searchable()
                        ->visible(fn (Get $get) => $get('type') === 'bonus_entries')->required(fn (Get $get) => $get('type') === 'bonus_entries'),
                    TextInput::make('stock')->integer()->minValue(0)->helperText('Empty for unlimited.'),
                    Select::make('min_tier_id')->label('Lowest tier')->relationship('minTier', 'name')->placeholder('Everyone'),
                    Toggle::make('active')->default(true),
                    Textarea::make('description')->maxLength(300)->rows(2)->columnSpanFull()
                        ->helperText('Custom rewards: say what it is and how you’ll get it to them.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            IconColumn::make('active')->boolean()->label('On'),
            TextColumn::make('points_per_pound')->label('Points per £1'),
            TextColumn::make('expiry_days')->label('Expiry')->formatStateUsing(fn ($state) => $state ? $state.' days' : 'Never')->placeholder('Never'),
            TextColumn::make('tiers_count')->counts('tiers')->label('Tiers'),
            TextColumn::make('rewards_count')->counts('rewards')->label('Rewards'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLoyaltyProgrammes::route('/'), 'create' => CreateLoyaltyProgramme::route('/create'), 'edit' => EditLoyaltyProgramme::route('/{record}/edit')];
    }
}
