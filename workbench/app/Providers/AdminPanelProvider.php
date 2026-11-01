<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Filament\Forms\Components\ColorPicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Psyao\Kami\Admin\InitialsAvatarProvider;
use Psyao\Kami\Admin\KamiPlugin;
use Workbench\App\Content\Fields\Colour;
use Workbench\App\Content\Project;
use Workbench\App\Filament\ProjectForm;
use Workbench\App\Filament\ProjectTable;

/** The host's own panel, as a site or an existing application declares it, with Kami added as a plugin. */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->strictAuthorization()
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->pages([Dashboard::class])
            ->plugin(KamiPlugin::make()
                ->fieldComponents([
                    Colour::class => fn (Colour $field): ColorPicker => ColorPicker::make($field->handle),
                ])
                ->forms([Project::class => ProjectForm::class])
                ->tables([Project::class => ProjectTable::class]))
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
                'kami.security-headers:admin',
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
