<?php

namespace App\Filament\Pages;

use App\Filament\Support\Money;
use App\Models\AdminAction;
use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class SiteSettings extends Page
{
    /** Admin roles (App\Support\Roles): the settings area. */
    public static function canAccess(): bool
    {
        return \App\Support\Roles::allows('settings');
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Settings';

    protected static ?string $title = 'Settings';

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'Site';

    public ?array $data = [];

    private const MONEY = ['min_deposit_pence' => 'Minimum top up', 'min_card_pence' => 'Minimum card payment', 'min_withdrawal_pence' => 'Minimum withdrawal',
        'max_top_up_pence' => 'Largest top up'];

    private const TEXT = ['featured_slug', 'instagram_url', 'facebook_url', 'whatsapp_url', 'tiktok_url', 'support_email', 'bonus_expiry_days', 'meta_pixel_id', 'meta_test_event_code',
        'vault_case_chips', 'vault_case_min_pence', 'vault_case_max_pence', 'vault_case_bonus_pct', 'vault_case_max_sold_pct', 'vault_case_slow_boost_pct',
        'vault_case_hold_minutes', 'vault_case_share_of_sales_pct',
        'referral_reward_type', 'referral_reward_tickets', 'referral_reward_credit_pence', 'referral_min_purchase_pence', 'referral_monthly_cap',
        'referral_voucher_days', 'referral_clawback_days', 'referral_flag_after',
        'payment_fee_bp', 'payment_fee_fixed_pence', 'monthly_revenue_target_pence', 'promo_budget_pct',
        'google_gtm_id', 'google_ga4_id', 'google_ads_id', 'google_ads_purchase_label', 'weglot_api_key'];

    /** On/off switches: shown as toggles, saved as 1/0. Both start off. */
    private const FLAGS = ['feature_vault_case', 'feature_referrals', 'require_skill_question'];

    public function mount(): void
    {
        $data = [];
        foreach ([...array_keys(self::MONEY), ...self::TEXT] as $k) {
            $data[$k] = Setting::get($k);
        }
        foreach (self::FLAGS as $k) {
            $data[$k] = Setting::bool($k);
        }
        $this->form->fill($data);
    }

    /** Plain-English status for the Meta section: never the token itself. */
    public static function metaStatus(): string
    {
        $token = (string) config('services.meta.capi_token') !== '' ? 'Access token is set on the server.' : 'No access token on the server yet (META_CAPI_TOKEN in Forge), so nothing is sent.';
        $day = \App\Models\MetaEvent::query()->where('updated_at', '>=', now()->subDay());
        $sent = (clone $day)->where('status', 'sent')->count();
        $failed = (clone $day)->where('status', 'failed')->count();
        $last = \App\Models\MetaEvent::query()->where('status', 'failed')->latest('updated_at')->value('last_error');

        return "Sales, top ups and sign-ups are also sent to Meta from the server, for visitors who accepted cookies. {$token} Last 24 hours: {$sent} sent, {$failed} failed".($last ? " (last error: {$last})" : '').'.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Money')->columns(3)->components(array_map(fn ($k) => Money::input($k, self::MONEY[$k])->required(), array_keys(self::MONEY))),
            Section::make('Bonus credit')->description('Credit you add to a player by hand, and bonus brought over from the old site.')->components([
                TextInput::make('bonus_expiry_days')->label('Bonus credit expires after (days)')->integer()->minValue(1)->maxValue(365)->required(),
            ]),
            Section::make('Site')->columns(2)->components([
                TextInput::make('featured_slug')->label('Featured competition (web address name)')->alphaDash(),
                TextInput::make('support_email')->email(),
                TextInput::make('instagram_url')->url()->prefix('https://')->rule('starts_with:https://'),
                TextInput::make('facebook_url')->url()->rule('starts_with:https://'),
                TextInput::make('whatsapp_url')->label('WhatsApp VIP group link')->url()->rule('starts_with:https://'),
                TextInput::make('tiktok_url')->label('TikTok')->url()->rule('starts_with:https://'),
                TextInput::make('meta_pixel_id')->label('Meta Pixel ID')->regex('/^\d{10,20}$/')
                    ->helperText('Numbers only, from Meta Events Manager. Leave empty for no Pixel. It only loads for visitors who accept marketing cookies.'),
            ]),
            Section::make('Meta tracking (server)')->description(fn () => self::metaStatus())->columns(2)->collapsible()->components([
                TextInput::make('meta_test_event_code')->label('Meta test event code')->regex('/^[A-Za-z0-9]{4,20}$/')
                    ->helperText('From Events Manager → Test events (e.g. TEST12345). While set, server events show in Test events instead of counting in your ads. Clear it before ads go live.'),
            ]),
            Section::make('Vault Case')->description('Darts-case basket builder for live draws. Off until you switch it on; prices are always the competition page prices.')->columns(3)->collapsible()->components([
                Toggle::make('feature_vault_case')->label('Vault Case on')->columnSpanFull()
                    ->helperText('Shows the home page section, /vault-case and the small links. It only appears when at least two live draws can go in a box.'),
                TextInput::make('vault_case_chips')->label('Budget buttons (pence, comma separated)')->regex('/^\d{3,6}(,\d{3,6}){0,5}$/')->required()->columnSpan(2),
                TextInput::make('vault_case_bonus_pct')->label('Bonus tickets (%)')->integer()->minValue(0)->maxValue(50)->required()
                    ->helperText('Free extra tickets on every box, rounded down. Counts towards each draw’s bundle share limit.'),
                Money::input('vault_case_min_pence', 'Smallest budget')->required(),
                Money::input('vault_case_max_pence', 'Largest budget')->required(),
                TextInput::make('vault_case_hold_minutes')->label('Hold tickets for (minutes)')->integer()->minValue(1)->maxValue(30)->required(),
                TextInput::make('vault_case_max_sold_pct')->label('Leave out draws over (% sold)')->integer()->minValue(50)->maxValue(100)->required(),
                TextInput::make('vault_case_slow_boost_pct')->label('Slow-seller boost (%)')->integer()->minValue(100)->maxValue(400)->required()
                    ->helperText('Draws selling slower than expected are this much more likely to be picked.'),
                TextInput::make('vault_case_share_of_sales_pct')->label('Report warning above (% of sales)')->integer()->minValue(1)->maxValue(100)->required()
                    ->helperText('The Vault Case report flags a draw where boxes are more than this share of its tickets.'),
            ]),
            Section::make('Refer A Friend')->description('Off until you switch it on. Load the draft terms first: php artisan platform:legal-pages --drafts, then edit them in Pages.')->columns(3)->collapsible()->components([
                Toggle::make('feature_referrals')->label('Refer a Friend on')->columnSpanFull(),
                Select::make('referral_reward_type')->label('Reward')->options(['tickets' => 'Free tickets (voucher)', 'credit' => 'Bonus credit'])->required()->native(false),
                TextInput::make('referral_reward_tickets')->label('Free tickets each')->integer()->minValue(1)->maxValue(100)->required(),
                Money::input('referral_reward_credit_pence', 'Bonus credit each')->required(),
                Money::input('referral_min_purchase_pence', 'Friend’s first order at least')->required(),
                TextInput::make('referral_monthly_cap')->label('Referrer rewards a month')->integer()->minValue(0)->maxValue(100)->required(),
                TextInput::make('referral_voucher_days')->label('Vouchers last (days)')->integer()->minValue(1)->maxValue(365)->required(),
                TextInput::make('referral_clawback_days')->label('Take back on refund within (days)')->integer()->minValue(0)->maxValue(120)->required(),
                TextInput::make('referral_flag_after')->label('Check by hand from (friends on one IP/device)')->integer()->minValue(2)->maxValue(50)->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $open = Setting::bool('sales_open');

        return $schema->components([
            Section::make('Google and Translation')->description('Google tags load only after a visitor accepts cookies (SOW 3.3). The Weglot key turns on the language switcher (SOW 2.17).')
                ->columns(2)->collapsible()->components([
                    \Filament\Forms\Components\TextInput::make('google_gtm_id')->label('Google Tag Manager ID')->placeholder('GTM-XXXXXXX')->regex('/^GTM-[A-Z0-9]{4,12}$/'),
                    \Filament\Forms\Components\TextInput::make('google_ga4_id')->label('Google Analytics 4 measurement ID')->placeholder('G-XXXXXXXXXX')->regex('/^G-[A-Z0-9]{4,20}$/'),
                    \Filament\Forms\Components\TextInput::make('google_ads_id')->label('Google Ads conversion ID')->placeholder('AW-123456789')->regex('/^AW-\d{6,15}$/'),
                    \Filament\Forms\Components\TextInput::make('google_ads_purchase_label')->label('Google Ads purchase conversion label')->regex('/^[A-Za-z0-9_-]{4,40}$/'),
                    \Filament\Forms\Components\TextInput::make('weglot_api_key')->label('Weglot API key (language switcher)')->placeholder('wg_…')->regex('/^wg_[A-Za-z0-9]{10,64}$/'),
                ]),
            Section::make('Reports')->description('Used by the Business Dashboard and the alerts (SOW 2.14).')->columns(2)->collapsible()->components([
                \Filament\Forms\Components\TextInput::make('payment_fee_bp')->label('Card fee (basis points: 200 = 2%)')->integer()->minValue(0)->maxValue(1000),
                \Filament\Forms\Components\TextInput::make('payment_fee_fixed_pence')->label('Card fee per payment (pence)')->integer()->minValue(0)->maxValue(500),
                Money::input('monthly_revenue_target_pence', 'Monthly sales target'),
                \Filament\Forms\Components\TextInput::make('promo_budget_pct')->label('Promotional budget (% of revenue)')->integer()->minValue(0)->maxValue(100)
                    ->helperText('E.g. 15 to 20. 0 for none.'),
                \Filament\Forms\Components\Toggle::make('require_skill_question')->label('Every competition needs a skill question to publish (SOW 2.2)'),
            ]),
            Section::make('Sales')->components([
                Text::make($open ? 'Sales are OPEN: players can buy, top up and enter.' : 'Sales are CLOSED: the site shows “Entries open soon”. Competitions, prizes and the free postal route stay visible.'),
                Actions::make([$this->salesAction()]),
            ]),
            Section::make('Email')->components([
                Text::make(fn () => 'Sending through '.config('mail.default').(config('mail.default') === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : '').' from '.config('mail.from.address').'.'),
                Actions::make([$this->testEmailAction()]),
            ]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->submit('save')->label('Save settings')])]),
        ]);
    }

    public function salesAction(): Action
    {
        $open = Setting::bool('sales_open');

        return Action::make('sales')->label($open ? 'Close sales' : 'Open sales')->color($open ? 'danger' : 'success')
            ->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(300)])
            ->action(function (array $data) use ($open) {
                Setting::put('sales_open', $open ? '0' : '1');
                AdminAction::record(auth()->user(), $open ? 'sales.closed' : 'sales.opened', null, $data['reason']);
                Notification::make()->success()->title($open ? 'Sales closed' : 'Sales open')->send();
            });
    }

    public function testEmailAction(): Action
    {
        return Action::make('testEmail')->label('Send test email')->color('gray')
            ->schema([TextInput::make('to')->label('Send to')->email()->required()->default(fn () => auth()->user()?->email)])
            ->action(function (array $data) {
                $mailer = (string) config('mail.default');
                if (in_array($mailer, ['log', 'array'], true)) {
                    Notification::make()->danger()->title('Email is not switched on')
                        ->body("The site is set to '{$mailer}', so nothing is sent. Set MAIL_MAILER=smtp in Forge → Environment, then run php artisan config:clear.")->persistent()->send();

                    return;
                }
                try {
                    // The real design (Brand Name mail theme), sent now rather than queued, so a failure shows here.
                    \Illuminate\Support\Facades\Notification::route('mail', $data['to'])->notifyNow(new \App\Notifications\TestEmail);
                } catch (\Throwable $e) {
                    AdminAction::record(auth()->user(), 'email.test_failed', null, null, ['mailer' => $mailer]);
                    Notification::make()->danger()->title('The email was not sent')->body(mb_substr($e->getMessage(), 0, 500))->persistent()->send();

                    return;
                }
                AdminAction::record(auth()->user(), 'email.test_sent', null, null, ['mailer' => $mailer]);
                Notification::make()->success()->title('Sent')->body('Accepted by the mail server. Check the inbox (and spam), and the Resend dashboard.')->persistent()->send();
            });
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $changed = [];
        foreach ($data as $k => $v) {
            $v = is_bool($v) ? ($v ? '1' : '0') : ($v === null || $v === '' ? null : (string) $v);
            if (Setting::get($k) !== $v) {
                Setting::put($k, $v);
                $changed[$k] = $v;
            }
        }
        if ($changed) {
            AdminAction::record(auth()->user(), 'settings.saved', null, null, $changed);
        }
        Notification::make()->success()->title($changed ? 'Saved' : 'Nothing changed')->send();
    }
}
