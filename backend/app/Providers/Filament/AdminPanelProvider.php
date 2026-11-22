<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\InitialsAvatar;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // Every admin signs in with a code from an authenticator app as well as the password; the first time,
            // Filament walks them through setting it up and gives recovery codes. Lost phone: platform:reset-two-step.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->brandName(config('app.name').' Admin')
            // The site's look: near-black with neon pink, cyan and yellow, the logo and the site's own fonts
            // (served from public/css/filament/platform, nothing third-party). Extra styling in public/css/filament/platform/admin.css.
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('css/filament/platform/favicon.png'))
            ->defaultAvatarProvider(InitialsAvatar::class)
            ->darkMode(true, true)
            ->font('Barlow', url: asset('css/filament/platform/fonts.css'), provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->maxContentWidth(Width::ScreenTwoExtraLarge)
            ->spa()
            ->colors([
                'primary' => Color::hex('#2FD36B'),
                'info' => Color::hex('#9AA8A1'),
                'success' => Color::hex('#2FD36B'),
                'warning' => Color::hex('#ffd23a'),
                'danger' => Color::hex('#D7322B'),
                'gray' => [
                    50 => 'oklch(0.975 0.006 310)', 100 => 'oklch(0.94 0.012 310)', 200 => 'oklch(0.87 0.02 310)',
                    300 => 'oklch(0.76 0.03 310)', 400 => 'oklch(0.62 0.035 310)', 500 => 'oklch(0.5 0.035 310)',
                    600 => 'oklch(0.4 0.035 310)', 700 => 'oklch(0.3 0.035 310)', 800 => 'oklch(0.22 0.03 310)',
                    900 => 'oklch(0.17 0.025 310)', 950 => 'oklch(0.12 0.02 310)',
                ],
            ])
            ->navigationGroups([
                NavigationGroup::make('Competitions'),
                NavigationGroup::make('Players & Money'),
                NavigationGroup::make('Marketing'),
                NavigationGroup::make('Site'),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new HtmlString('<link rel="stylesheet" href="'.e(asset('css/filament/platform/admin.css')).'?v='.@filemtime(public_path('css/filament/platform/admin.css')).'">'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
