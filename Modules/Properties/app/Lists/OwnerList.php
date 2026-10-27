<?php

declare(strict_types=1);

namespace Modules\Properties\Lists;

use App\Exports\ExportColumn;
use App\Exports\ListFilters;
use App\Exports\QueryListExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\Properties\Assignments\AgentNarrowing;
use Modules\Properties\Models\OwnerProfile;

/**
 * The owners list (/o/{slug}/owners, Livewire\OwnerProfileIndex) and its
 * export (P02-07): owners the member sees (an agent limited to some
 * buildings: their owners only, AgentNarrowing), active or archived, with
 * the search over name, email and phone, by name.
 *
 * The export's headers are the owners import's, so a file exported here
 * imports again. ID numbers are encrypted and never exported: the column
 * shows the masked number the page shows ("NIN ···7890"). It holds
 * people's contact details, so OwnerProfilePolicy::export refuses it to
 * GoProperty staff while impersonating.
 *
 * @extends QueryListExport<OwnerProfile>
 */
final class OwnerList extends QueryListExport
{
    public function __construct(private readonly AgentNarrowing $narrowing) {}

    public function key(): string
    {
        return 'owners';
    }

    public function title(): string
    {
        return 'Owners';
    }

    public function filters(array $input): array
    {
        return ListFilters::set([
            'search' => ListFilters::search($input['search'] ?? null),
            'archived' => ListFilters::flag($input['archived'] ?? false),
        ]);
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', OwnerProfile::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $search = is_string($filters['search'] ?? null) ? $filters['search'] : null;

        return $this->narrowing->owners(OwnerProfile::query(), $user)
            ->when(($filters['archived'] ?? false) === true, fn (Builder $query) => $query->onlyTrashed())
            ->when($search !== null, function (Builder $query) use ($search): void {
                $like = ListFilters::like((string) $search);
                $digits = preg_replace('/\D/', '', (string) $search) ?? '';

                $query->where(function (Builder $query) use ($like, $digits): void {
                    $query->where('display_name', 'like', $like)->orWhere('email', 'like', $like);

                    // Phones are stored as +234…; "0803 123" finds +234803123….
                    if (strlen($digits) >= 4) {
                        $query->orWhere('phone', 'like', '%'.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits).'%');
                    }
                });
            })
            ->orderBy('display_name')
            ->orderBy('id');
    }

    protected function exportQuery(User $user, array $filters): Builder
    {
        // Only the buildings the member sees are counted.
        return $this->query($user, $filters)->withCount(['properties' => fn (Builder $buildings) => $this->narrowing->buildings($buildings, $user)]);
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Your ID', fn (OwnerProfile $owner) => $owner->external_ref),
            ExportColumn::make('Name', fn (OwnerProfile $owner) => $owner->display_name),
            ExportColumn::make('Type', fn (OwnerProfile $owner) => $owner->type),
            ExportColumn::make('Phone', fn (OwnerProfile $owner) => $owner->phone),
            ExportColumn::make('Email', fn (OwnerProfile $owner) => $owner->email),
            ExportColumn::make('Address', fn (OwnerProfile $owner) => $owner->address),
            ExportColumn::make('State', fn (OwnerProfile $owner) => $owner->state),
            ExportColumn::make('LGA', fn (OwnerProfile $owner) => $owner->lga),
            // Encrypted at rest: never exported in full (AGENT.md § 5).
            ExportColumn::make('ID (masked)', fn (OwnerProfile $owner) => $owner->maskedId()),
            ExportColumn::make('Notes', fn (OwnerProfile $owner) => $owner->notes),
            ExportColumn::make('Buildings', fn (OwnerProfile $owner): int => (int) $owner->getAttribute('properties_count')),
            ExportColumn::make('Added on', fn (OwnerProfile $owner) => $owner->created_at),
            ExportColumn::make('Archived on', fn (OwnerProfile $owner) => $owner->deleted_at),
        ];
    }
}
