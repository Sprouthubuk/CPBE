<?php

namespace App\Filament\Resources\Memberships;

use App\Filament\Resources\Memberships\Pages\CreateMembershipTier;
use App\Filament\Resources\Memberships\Pages\EditMembershipTier;
use App\Filament\Resources\Memberships\Pages\ListMembershipTiers;
use App\Filament\Support\Money;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
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

/** Membership tiers (SOW 2.12): as many as the Client needs, each with its price, eligibility and perks. */
class MembershipTierResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing');
    }

    protected static ?string $model = MembershipTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Membership Tiers';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tier')->columns(2)->columnSpanFull()->components([
                TextInput::make('name')->required()->maxLength(60),
                Money::input('price_pence', 'Price a month')->required(),
                Toggle::make('active')->label('Open to join')->default(false)
                    ->helperText('Switching it off stops new joiners; existing members keep renewing until you end them.'),
                TextInput::make('sort')->integer()->default(0)->label('Order on the page'),
                Textarea::make('description')->maxLength(500)->columnSpanFull(),
            ]),
            Section::make('Who can join')->columns(3)->columnSpanFull()->components([
                Select::make('eligibility')->options(MembershipTier::ELIGIBILITY)->default('anyone')->required()->live(),
                Money::input('min_spend_pence', 'Spent at least')->visible(fn (Get $get) => $get('eligibility') === 'min_spend'),
                TextInput::make('max_members')->integer()->minValue(1)->helperText('Empty for no limit.'),
            ]),
            Section::make('Perks each paid month')->columns(2)->columnSpanFull()->components([
                TextInput::make('monthly_free_tickets')->label('Free entries (a voucher for any live draw)')->integer()->minValue(0)->maxValue(1000)->default(0),
                Money::input('monthly_bonus_credit_pence', 'Bonus credit')->default(0),
                TextInput::make('early_access_hours')->label('Early access (hours before a competition opens)')->integer()->minValue(0)->maxValue(168)->default(0)
                    ->helperText('Applies to competitions published after this is set.'),
                TextInput::make('loyalty_bonus_pct')->label('Extra loyalty points (%)')->integer()->minValue(0)->maxValue(900)->default(0),
                Textarea::make('perks')->label('Other perks (one a line, shown to players)')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('price_pence')->label('A month')->formatStateUsing(fn ($state) => Money::show($state)),
            IconColumn::make('active')->boolean()->label('Open'),
            TextColumn::make('members')->state(fn (MembershipTier $t) => $t->memberships()->whereIn('status', Membership::CURRENT)->count())->label('Members'),
            TextColumn::make('mrr')->label('Monthly revenue')->state(fn (MembershipTier $t) => Money::show($t->memberships()->where('status', 'active')->count() * $t->price_pence)),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListMembershipTiers::route('/'), 'create' => CreateMembershipTier::route('/create'), 'edit' => EditMembershipTier::route('/{record}/edit')];
    }
}
