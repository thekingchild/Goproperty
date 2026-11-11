<?php

declare(strict_types=1);

namespace Modules\LateFees\Providers;

use App\Models\Organization;
use App\Models\User;
use App\Navigation\SettingsTab;
use App\Navigation\SettingsTabs;
use App\Settings\SettingDefinition;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use App\Settings\SettingType;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;
use Modules\LateFees\Charges\LateFeeSettings;
use Modules\LateFees\Jobs\ChargeLateFees;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * The LateFees module (PL-02, docs/plans/late-fees-2026-11-11.md): an
 * agency's late fees on overdue rent, charged nightly as fee bills through
 * Billing's Contracts\LateFeeBills, set in Settings → Late fees. It reads
 * the other modules only through their contracts (ARCHITECTURE.md § 8
 * rule 3).
 */
final class LateFeesServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'LateFees';

    protected string $nameLower = 'latefees';

    /** @var list<class-string> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        Livewire::addNamespace($this->nameLower, classNamespace: 'Modules\\LateFees\\Livewire');

        $this->callAfterResolving(SettingsRegistry::class, static function (SettingsRegistry $settings): void {
            $settings->define(new SettingDefinition(LateFeeSettings::ENABLED, SettingType::Boolean, false, ['boolean'], SettingScope::Organization));
            $settings->define(new SettingDefinition(LateFeeSettings::RATE_BPS, SettingType::Integer, LateFeeSettings::DEFAULT_RATE_BPS, ['integer', 'between:'.LateFeeSettings::MIN_RATE_BPS.','.LateFeeSettings::MAX_RATE_BPS], SettingScope::Organization));
            $settings->define(new SettingDefinition(LateFeeSettings::GRACE_DAYS, SettingType::Integer, LateFeeSettings::DEFAULT_GRACE_DAYS, ['integer', 'between:1,'.LateFeeSettings::MAX_GRACE_DAYS], SettingScope::Organization));
            $settings->define(new SettingDefinition(LateFeeSettings::CAP_KOBO, SettingType::Integer, null, ['nullable', 'integer', 'between:'.LateFeeSettings::MIN_CAP_KOBO.','.LateFeeSettings::MAX_CAP_KOBO], SettingScope::Organization));
        });

        $this->callAfterResolving(SettingsTabs::class, static function (SettingsTabs $tabs): void {
            $tabs->add(new SettingsTab(
                key: 'late-fees',
                label: 'Late fees',
                route: 'workspace.settings.payments.late-fees',
                visible: static fn (User $user, Organization $organization): bool => $user->can('viewSettings', $organization),
                order: 28,
            ));
        });

        // After `bills-flag-overdue` (01:00), on the billing lane (AGENT.md § 4: Schedule::job only).
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->job(new ChargeLateFees, 'billing')->name('late-fees-charge')->dailyAt('01:20');
        });
    }
}
