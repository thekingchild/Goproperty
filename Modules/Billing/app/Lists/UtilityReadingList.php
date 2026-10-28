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
use Modules\Billing\Models\UtilityReading;
use Modules\Properties\Contracts\BuildingAccess;

/**
 * The meter readings list (/o/{slug}/utilities/readings,
 * Livewire\UtilityReadingIndex) and its export (P02-07; P03-06a): kept
 * readings, newest first, in the member's buildings (an assigned agent:
 * theirs only, through the setting's building and
 * Modules\Properties\Contracts\BuildingAccess), filtered by building,
 * utility and one unit's meter (the units' utilities list links to it).
 * Who recorded each reading is a member's name; no contact details.
 */
final class UtilityReadingList implements ListExport
{
    public const int CHUNK = 500;

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly UtilityRows $rows,
    ) {}

    public function key(): string
    {
        return 'utility_readings';
    }

    public function title(): string
    {
        return 'Meter readings';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'building' => ListFilters::id($input['building'] ?? null),
            'utility' => ListFilters::id($input['utility'] ?? null),
            'meter' => ListFilters::id($input['meter'] ?? null),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', UtilityReading::class);
    }

    /**
     * @param  array<string, string|int|bool>  $filters  cleaned
     * @return Builder<UtilityReading>
     */
    public function query(User $user, array $filters): Builder
    {
        $limited = $this->access->limitedTo($user);
        $building = is_int($filters['building'] ?? null) ? $filters['building'] : null;
        $utility = is_int($filters['utility'] ?? null) ? $filters['utility'] : null;
        $narrowed = $limited !== null || $building !== null || $utility !== null;

        return UtilityReading::query()
            ->with('setting.utilityType')
            ->when($narrowed, fn (Builder $query) => $query->whereIn('unit_utility_setting_id', UnitUtilitySetting::query()->select('id')
                ->when($limited !== null, fn (Builder $settings) => $settings->whereIn('property_id', $limited ?? []))
                ->when($building !== null, fn (Builder $settings) => $settings->where('property_id', $building))
                ->when($utility !== null, fn (Builder $settings) => $settings->where('utility_type_id', $utility))))
            ->when(is_int($filters['meter'] ?? null), fn (Builder $query) => $query->where('unit_utility_setting_id', $filters['meter']))
            ->orderByDesc('reading_date')
            ->orderByDesc('id');
    }

    /** @return iterable<UtilityReadingRow> */
    public function rows(User $user, array $filters): iterable
    {
        foreach ($this->query($user, $filters)->lazy(self::CHUNK)->chunk(self::CHUNK) as $chunk) {
            yield from $this->rows->readings($chunk);
        }
    }

    public function count(User $user, array $filters): int
    {
        return $this->query($user, $filters)->toBase()->getCountForPagination();
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Date', fn (UtilityReadingRow $row) => ExportCell::date($row->reading->reading_date)),
            ExportColumn::make('Building', fn (UtilityReadingRow $row) => $row->buildingName),
            ExportColumn::make('Unit', fn (UtilityReadingRow $row) => $row->unitLabel),
            ExportColumn::make('Utility', fn (UtilityReadingRow $row) => $row->reading->setting->utilityType->name),
            ExportColumn::make('Previous reading', fn (UtilityReadingRow $row) => $row->reading->previous_value->input()),
            ExportColumn::make('Reading', fn (UtilityReadingRow $row) => $row->reading->current_value->input()),
            ExportColumn::make('Consumption', fn (UtilityReadingRow $row) => $row->reading->consumption->input()),
            ExportColumn::make('Unit of measure', fn (UtilityReadingRow $row) => $row->reading->setting->utilityType->unit_of_measure?->label()),
            ExportColumn::make('Meter replaced', fn (UtilityReadingRow $row) => $row->reading->meter_replaced),
            ExportColumn::make('Rate', fn (UtilityReadingRow $row) => $row->reading->rate_kobo),
            ExportColumn::make('Billed', fn (UtilityReadingRow $row) => $row->reading->isBilled()),
            ExportColumn::make('Recorded by', fn (UtilityReadingRow $row) => $row->recordedBy),
            ExportColumn::make('Recorded on', fn (UtilityReadingRow $row) => $row->reading->created_at),
            ExportColumn::make('Note', fn (UtilityReadingRow $row) => $row->reading->note),
        ];
    }
}
