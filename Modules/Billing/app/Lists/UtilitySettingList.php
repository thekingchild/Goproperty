<?php

declare(strict_types=1);

namespace Modules\Billing\Lists;

use App\Exports\ExportCell;
use App\Exports\ExportColumn;
use App\Exports\ListExport;
use App\Exports\ListFilters;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Models\UnitUtilitySetting;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * The units' utilities list (/o/{slug}/utilities/units,
 * Livewire\UtilitySettingIndex) and its export (P02-07; P03-06a): which
 * utilities each unit is charged, at what amount and cycle (the unit's
 * override or the type's default), switched on or off, with the latest
 * meter reading of metered ones; in the member's buildings (an assigned
 * agent: theirs only, Modules\Properties\Contracts\BuildingAccess),
 * filtered by building, utility, unit and status. Unit and building names
 * come from the Properties contracts (Lists\UtilityRows). No contact
 * details.
 */
final class UtilitySettingList implements ListExport
{
    public const int CHUNK = 500;

    public const string ON = 'on';

    public const string OFF = 'off';

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly UtilityRows $rows,
    ) {}

    public function key(): string
    {
        return 'utility_settings';
    }

    public function title(): string
    {
        return 'Units\' utilities';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'building' => ListFilters::id($input['building'] ?? null),
            'utility' => ListFilters::id($input['utility'] ?? null),
            'unit' => ListFilters::id($input['unit'] ?? null),
            'status' => ListFilters::option($input['status'] ?? null, [self::ON, self::OFF]),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', UnitUtilitySetting::class);
    }

    /**
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<UnitUtilitySetting>
     */
    public function query(User $user, array $filters): Builder
    {
        $limited = $this->access->limitedTo($user);
        $status = $filters['status'] ?? null;

        return UnitUtilitySetting::query()
            ->with('utilityType')
            ->when($limited !== null, fn (Builder $query) => $query->whereIn('property_id', $limited ?? []))
            ->when(is_int($filters['building'] ?? null), fn (Builder $query) => $query->where('property_id', $filters['building']))
            ->when(is_int($filters['utility'] ?? null), fn (Builder $query) => $query->where('utility_type_id', $filters['utility']))
            ->when(is_int($filters['unit'] ?? null), fn (Builder $query) => $query->where('unit_id', $filters['unit']))
            ->when($status === self::ON, fn (Builder $query) => $query->where('active', true))
            ->when($status === self::OFF, fn (Builder $query) => $query->where('active', false))
            ->orderBy('property_id')
            ->orderBy('unit_id')
            ->orderBy('utility_type_id')
            ->orderBy('id');
    }

    /** @return iterable<UtilitySettingRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->rows->settings($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Building', fn (UtilitySettingRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (UtilitySettingRow $row) => $row->unitLabel),
            ExportColumn::make('Utility', fn (UtilitySettingRow $row) => $row->setting->utilityType->name),
            ExportColumn::make('Charging', fn (UtilitySettingRow $row) => $row->setting->utilityType->charging_mode->shortLabel()),
            ExportColumn::make('Amount', fn (UtilitySettingRow $row) => $row->setting->amount()),
            ExportColumn::make('Unit of measure', fn (UtilitySettingRow $row) => $row->setting->utilityType->unit_of_measure?->label()),
            ExportColumn::make('Amount set for this unit', fn (UtilitySettingRow $row) => $row->setting->amount_kobo !== null),
            ExportColumn::make('Cycle', fn (UtilitySettingRow $row) => $row->setting->billingCycle()),
            ExportColumn::make('Status', fn (UtilitySettingRow $row) => $row->setting->active ? 'On' : 'Off'),
            ExportColumn::make('Applies from', fn (UtilitySettingRow $row) => ExportCell::date($row->setting->effective_from)),
            ExportColumn::make('Starting reading', fn (UtilitySettingRow $row) => $row->setting->starting_reading?->input()),
            ExportColumn::make('Last reading', fn (UtilitySettingRow $row) => $row->lastReading?->current_value->input()),
            ExportColumn::make('Last reading date', fn (UtilitySettingRow $row) => ExportCell::date($row->lastReading?->reading_date)),
        ];
    }
}
