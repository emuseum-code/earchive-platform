<?php

namespace App\Providers\Filament;

use App\Filament\LocalAvatarProvider;
use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            ->profile(isSimple: false)
            ->brandName('Архів: розпізнавання архівних документів')
            ->favicon(asset('favicon.svg'))
            ->colors(['primary' => Color::Indigo, 'gray' => Color::Slate, 'ai' => Color::Blue])
            ->defaultAvatarProvider(LocalAvatarProvider::class)
            ->font('Inter')
            ->maxContentWidth('full')
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications(false)
            ->globalSearch()
            ->globalSearchKeyBindings(['ctrl+k', 'mod+k'])
            ->unsavedChangesAlerts()
            ->navigationGroups([
                NavigationGroup::make('Облік сканів'),
                NavigationGroup::make('Розпізнавання'),
                NavigationGroup::make('Дані та звірка'),
                NavigationGroup::make('Адаптація й експорт'),
                NavigationGroup::make('Адміністрування'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => Blade::render('<link rel="stylesheet" href="{{ asset(\'css/books.css\') }}?v=6">'))
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn () => config('services.demo_login') ? view('filament.partials.demo-login') : '')
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.partials.account'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
