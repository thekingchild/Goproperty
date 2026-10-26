<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use App\Models\PlatformStaff;
use App\Theme\ThemeManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Settings → Versions (PLATFORM_CONSOLE.md § 9.8): every published version
 * of the theme, website and SEO settings. Restoring one starts a draft from
 * it, to check in the preview before publishing.
 */
final class ThemeVersions extends Page
{
    protected static ?string $slug = 'settings/versions';

    protected static ?string $title = 'Published versions';

    protected static ?string $navigationLabel = 'Versions';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 5;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected string $view = 'console.settings.versions';

    public static function canAccess(): bool
    {
        return Gate::forUser(Filament::auth()->user())->allows('settings.theme');
    }

    /** @return list<array{version: int, status: string, published_at: string|null, by: string|null}> */
    public function getVersions(): array
    {
        $versions = app(ThemeManager::class)->versions();
        $names = PlatformStaff::query()->whereIn('id', array_filter(array_column($versions, 'published_by_staff_id')))->pluck('name', 'id');

        return array_map(fn (array $version): array => [
            'version' => $version['version'],
            'status' => $version['status'],
            'published_at' => $version['published_at'],
            'by' => $version['published_by_staff_id'] !== null ? $names->get($version['published_by_staff_id']) : null,
        ], $versions);
    }

    public function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore as draft')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(fn (array $arguments): string => 'Starts a draft from version '.(int) ($arguments['version'] ?? 0).'. Visitors keep the published version until you publish the draft.')
            ->action(function (array $arguments): void {
                $staff = Filament::auth()->user();
                $version = (int) ($arguments['version'] ?? 0);
                app(ThemeManager::class)->restore($version, $staff instanceof PlatformStaff ? $staff : null);

                Notification::make()->title("Draft started from version {$version}")->body('Check it in the preview, then publish.')->success()->send();
                $this->redirect(BrandAppearance::getUrl());
            });
    }
}
