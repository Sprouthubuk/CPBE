<?php

namespace App\Filament\Resources\ReferralSchemes;

use App\Filament\Resources\ReferralSchemes\Pages\CreateReferralScheme;
use App\Filament\Resources\ReferralSchemes\Pages\EditReferralScheme;
use App\Filament\Resources\ReferralSchemes\Pages\ListReferralSchemes;
use App\Filament\Support\Money;
use App\Models\LoyaltyProgramme;
use App\Models\ReferralReward;
use App\Models\ReferralScheme;
use App\Support\Roles;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Referral schemes (SOW 2.13). Refer a Friend must also be switched on in Settings. While any scheme exists,
 * new referrals join the first running scheme the referrer can use; with none set up, the Settings rewards apply.
 */
class ReferralSchemeResource extends Resource
{
    public static function canAccess(): bool
    {
        return Roles::allows('marketing');
    }

    protected static ?string $model = ReferralScheme::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Referral Schemes';

    protected static ?int $navigationSort = 12;

    private static function amount(string $column, string $label): TextInput
    {
        return TextInput::make($column)->label($label)->integer()->minValue(0)->default(0)
            ->helperText('Entries, pence of credit, or points, by the reward type.');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scheme')->columns(3)->columnSpanFull()->components([
                TextInput::make('name')->required()->maxLength(80),
                Toggle::make('active')->label('Switched on')->default(false),
                Select::make('who_can_refer')->options(['all' => 'All players', 'members' => 'Members only'])->default('all')->required(),
                DateTimePicker::make('starts_at')->timezone('Europe/London')->seconds(false),
                DateTimePicker::make('ends_at')->timezone('Europe/London')->seconds(false)->after('starts_at'),
            ]),
            Section::make('What the friend must do')->columns(2)->columnSpanFull()->components([
                Select::make('qualifying')->options(ReferralScheme::QUALIFYING)->default('first_order')->required(),
                Money::input('min_spend_pence', 'Minimum spend')->default(0),
            ]),
            Section::make('Rewards')->columns(2)->columnSpanFull()->components([
                Select::make('friend_reward_type')->label('Friend gets')->options(ReferralScheme::REWARDS)->default('tickets')->required(),
                self::amount('friend_reward_amount', 'Amount'),
                Select::make('referrer_reward_type')->label('Referrer gets')->options(ReferralScheme::REWARDS)->default('tickets')->required(),
                self::amount('referrer_reward_amount', 'Amount'),
                Select::make('loyalty_programme_id')->label('Loyalty programme (for points)')->options(fn () => LoyaltyProgramme::query()->pluck('name', 'id')->all()),
                TextInput::make('monthly_cap')->label('Rewards a referrer can get a month')->integer()->minValue(0)->helperText('Empty for no limit.'),
                TextInput::make('total_cap')->label('Rewarded referrals for the whole scheme')->integer()->minValue(0)->helperText('Empty for no limit.'),
            ]),
            Section::make('Multi-level rewards')->columnSpanFull()->components([
                Placeholder::make('legal')->hiddenLabel()->content('Rewards for referrals made by the people a player referred. Multi-level schemes can raise legal issues: take legal advice before switching one on (SOW 2.13).'),
                Repeater::make('levels')->label('Levels (level 2 first)')->columns(2)->defaultItems(0)->maxItems(ReferralScheme::MAX_LEVELS - 1)
                    ->addActionLabel('Add a level')
                    ->components([
                        Select::make('type')->options(ReferralScheme::REWARDS)->required(),
                        TextInput::make('amount')->integer()->minValue(0)->required(),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            IconColumn::make('active')->boolean()->label('On'),
            TextColumn::make('qualifying')->formatStateUsing(fn ($state) => ReferralScheme::QUALIFYING[$state] ?? $state),
            TextColumn::make('referrals_count')->counts('referrals')->label('Referrals'),
            TextColumn::make('rewarded')->label('Rewarded')->state(fn (ReferralScheme $s) => $s->referrals()->where('status', 'rewarded')->count()),
            TextColumn::make('credit')->label('Credit given')->state(fn (ReferralScheme $s) => Money::show((int) ReferralReward::query()
                ->whereIn('referral_id', $s->referrals()->select('id'))->where('type', 'credit')->where('status', 'given')->sum('amount'))),
            TextColumn::make('tickets')->label('Free entries given')->state(fn (ReferralScheme $s) => (int) ReferralReward::query()
                ->whereIn('referral_id', $s->referrals()->select('id'))->where('type', 'tickets')->where('status', 'given')->sum('amount')),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListReferralSchemes::route('/'), 'create' => CreateReferralScheme::route('/create'), 'edit' => EditReferralScheme::route('/{record}/edit')];
    }
}
