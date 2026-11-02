<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Lists;

use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\Properties\Contracts\BuildingAccess;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * The organization's Building Groups (/o/{slug}/groups,
 * Livewire\GroupIndex) and their export (P02-07; P07-02): one per
 * building, open ones first, with the current tenant members, the
 * published posts and the time of the latest activity. An assigned
 * agent's list holds their buildings only. Never the posts' words.
 *
 * @extends QueryListExport<BuildingGroup>
 */
final class GroupList extends QueryListExport
{
    /** @var array<int, string> building id => name, for the export's rows */
    private array $names = [];

    public function __construct(
        private readonly BuildingAccess $access,
        private readonly PropertyDirectory $buildings,
    ) {}

    public function key(): string
    {
        return 'building_groups';
    }

    public function title(): string
    {
        return 'Building Groups';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', BuildingGroup::class);
    }

    public function query(User $user, array $filters): Builder
    {
        $limited = $this->access->limitedTo($user);

        return BuildingGroup::query()
            ->when($limited !== null, static fn (Builder $query) => $query->whereIn('property_id', $limited))
            ->withCount([
                'members as members_count' => static fn (Builder $query) => $query->whereNull('left_at'),
                'posts as posts_count' => static fn (Builder $query) => $query->where('status', PostStatus::Published),
            ])
            ->withMax(['posts as latest_activity_at' => static fn (Builder $query) => $query->where('status', PostStatus::Published)], 'last_activity_at')
            ->orderByDesc('is_active')
            ->orderBy('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Group', fn (BuildingGroup $group): string => $this->nameOf($group)),
            ExportColumn::make('Status', static fn (BuildingGroup $group): string => $group->is_active ? 'Open' : 'Closed'),
            ExportColumn::make('Members', static fn (BuildingGroup $group): int => (int) $group->getAttribute('members_count')),
            ExportColumn::make('Posts', static fn (BuildingGroup $group): int => (int) $group->getAttribute('posts_count')),
            ExportColumn::make('Latest activity', static fn (BuildingGroup $group): ?CarbonImmutable => self::latestActivity($group)),
            ExportColumn::make('Opened on', static fn (BuildingGroup $group) => $group->created_at),
            ExportColumn::make('Closed on', static fn (BuildingGroup $group) => $group->closed_at),
        ];
    }

    /** When a post of the group was last published or commented on (stored in UTC), or null. */
    public static function latestActivity(BuildingGroup $group): ?CarbonImmutable
    {
        $value = $group->getAttribute('latest_activity_at');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC') : null;
    }

    /** The group's name: its own, else its building's. */
    public function nameOf(BuildingGroup $group): string
    {
        if ($group->name !== null && $group->name !== '') {
            return $group->name;
        }

        return $this->names[$group->property_id] ??= $this->buildings->find($group->property_id)->name ?? __('Building #:id', ['id' => $group->property_id]);
    }

    /**
     * Building names for a page of groups at once.
     *
     * @param  iterable<BuildingGroup>  $groups
     */
    public function remember(iterable $groups): void
    {
        $ids = [];

        foreach ($groups as $group) {
            $ids[] = $group->property_id;
        }

        foreach ($this->buildings->findMany(array_values(array_unique($ids))) as $id => $building) {
            $this->names[$id] = $building->name;
        }
    }
}
