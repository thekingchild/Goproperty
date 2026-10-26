<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Console\ConsolePermissions;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fresh for every request and queued job (ARCHITECTURE.md § 4).
        $this->app->scoped(OrganizationContext::class);

        // The published theme unless the console preview switches to the draft.
        $this->app->scoped(ThemeContext::class);

        $this->app->singleton(SettingsRegistry::class, function (): SettingsRegistry {
            $registry = new SettingsRegistry;
            $definitions = config('settings.definitions', []);

            foreach (is_array($definitions) ? $definitions : [] as $key => $definition) {
                if (is_string($key) && is_array($definition)) {
                    $registry->define(SettingDefinition::fromArray($key, $definition));
                }
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        ConsolePermissions::register($this->app->make(Gate::class));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
