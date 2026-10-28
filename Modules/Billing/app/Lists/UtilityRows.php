<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use App\Models\User;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Billing\Models\UtilityReading;
use Modules\Properties\Contracts\PropertyDirectory;
use Modules\Properties\Contracts\PropertySummary;
use Modules\Properties\Contracts\UnitDirectory;
use Modules\Properties\Contracts\UnitSummary;

/**
 * The unit and building names of a batch of units' utilities or meter
 * readings (P03-06a), read through the Properties contracts with one
 * lookup each per batch (no query per row, ARCHITECTURE.md § 13), never by
 * joining their tables (§ 8 rule 3); with each setting's latest kept
 * reading, and who recorded each reading, also one query per batch. An
 * archived unit is no longer found: it shows as "Archived unit".
 */
final readonly class UtilityRows
{
    public function __construct(
        private UnitDirectory $units,
        private PropertyDirectory $buildings,
    ) {}

    /**
     * @param  iterable<UnitUtilitySetting>  $settings
     * @return list<UtilitySettingRow>
     */
    public function settings(iterable $settings): array
    {
        $settings = is_array($settings) ? array_values($settings) : iterator_to_array($settings, false);

        if ($settings === []) {
            return [];
        }

        [$units, $buildings] = $this->places($settings);
        $latest = $this->latestReadings(array_map(static fn (UnitUtilitySetting $setting): int => $setting->id, $settings));

        return array_map(fn (UnitUtilitySetting $setting): UtilitySettingRow => new UtilitySettingRow(
            setting: $setting,
            unitLabel: isset($units[$setting->unit_id]) ? $units[$setting->unit_id]->label : self::text('Archived unit'),
            buildingName: isset($buildings[$setting->property_id]) ? $buildings[$setting->property_id]->name : self::text('Removed building'),
            unitArchived: ! isset($units[$setting->unit_id]),
            lastReading: $latest[$setting->id] ?? null,
        ), $settings);
    }

    /**
     * @param  iterable<UtilityReading>  $readings  with their setting loaded
     * @return list<UtilityReadingRow>
     */
    public function readings(iterable $readings): array
    {
        $readings = is_array($readings) ? array_values($readings) : iterator_to_array($readings, false);

        if ($readings === []) {
            return [];
        }

        [$units, $buildings] = $this->places(array_map(static fn (UtilityReading $reading): UnitUtilitySetting => $reading->setting, $readings));
        $userIds = array_values(array_unique(array_filter(array_map(static fn (UtilityReading $reading): ?int => $reading->recorded_by_user_id, $readings))));
        $names = $userIds === [] ? [] : User::query()->whereKey($userIds)->pluck('name', 'id')->all();

        return array_map(static fn (UtilityReading $reading): UtilityReadingRow => new UtilityReadingRow(
            reading: $reading,
            unitLabel: isset($units[$reading->setting->unit_id]) ? $units[$reading->setting->unit_id]->label : self::text('Archived unit'),
            buildingName: isset($buildings[$reading->setting->property_id]) ? $buildings[$reading->setting->property_id]->name : self::text('Removed building'),
            recordedBy: $reading->recorded_by_user_id !== null && is_string($names[$reading->recorded_by_user_id] ?? null) ? $names[$reading->recorded_by_user_id] : null,
        ), $readings);
    }

    /**
     * The latest kept reading of each of these meters (one query). A new
     * reading is always dated after every kept one, so the latest by date
     * is also the highest id among the kept readings.
     *
     * @param  list<int>  $settingIds
     * @return array<int, UtilityReading> by setting id
     */
    public function latestReadings(array $settingIds): array
    {
        if ($settingIds === []) {
            return [];
        }

        return UtilityReading::query()
            ->whereIn('id', UtilityReading::query()->selectRaw('max(id)')->whereIn('unit_utility_setting_id', $settingIds)->groupBy('unit_utility_setting_id'))
            ->get()
            ->keyBy('unit_utility_setting_id')
            ->all();
    }

    /**
     * @param  list<UnitUtilitySetting>  $settings
     * @return array{0: array<int, UnitSummary>, 1: array<int, PropertySummary>}
     */
    private function places(array $settings): array
    {
        return [
            $this->units->findMany(array_values(array_unique(array_map(static fn (UnitUtilitySetting $setting): int => $setting->unit_id, $settings)))),
            $this->buildings->findMany(array_values(array_unique(array_map(static fn (UnitUtilitySetting $setting): int => $setting->property_id, $settings)))),
        ];
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
