<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Models\GroupReport;
use Modules\BuildingGroup\Posts\RichText;
use Modules\Tenants\Contracts\TenantDirectory;

/**
 * The group's open reports for its moderators (P07-04), by item, the most
 * reported first, then the oldest: who reported it, why, their note, and a
 * plain-text excerpt of the item (never its markup). Items whose authors
 * removed them meanwhile drop out. In the group's organization's context,
 * for a viewer the page has let in (`viewModeration`).
 */
final readonly class ReportQueue
{
    /** Open reports read at most. */
    public const int LIMIT = 200;

    public function __construct(
        private RichText $richText,
        private TenantDirectory $tenants,
    ) {}

    /** @return list<ReportedItem> $viewer's own items marked `mine` */
    public function open(BuildingGroup $group, User $viewer): array
    {
        $reports = GroupReport::query()->open()->where('building_group_id', $group->id)->orderBy('id')->limit(self::LIMIT)->get();

        if ($reports->isEmpty()) {
            return [];
        }

        $postIds = $reports->where('reportable_type', ReactableType::Post)->pluck('reportable_id')->all();
        $commentIds = $reports->where('reportable_type', ReactableType::Comment)->pluck('reportable_id')->all();
        $posts = GroupPost::query()->moderatable()->where('building_group_id', $group->id)->whereIn('id', $postIds)->get()->keyBy('id');
        $comments = $commentIds === [] ? collect() : GroupComment::query()->moderatable()->whereIn('id', $commentIds)->get()->keyBy('id');

        $tenantIds = [];
        $userIds = [];

        foreach ([...$reports->all(), ...$posts->all(), ...$comments->all()] as $row) {
            [$kind, $user, $tenant] = $row instanceof GroupReport
                ? [$row->reporter_kind, $row->reporter_user_id, $row->reporter_tenant_profile_id]
                : [$row->author_kind, $row->author_user_id, $row->author_tenant_profile_id];

            if ($kind === AuthorKind::Tenant && $tenant !== null) {
                $tenantIds[] = $tenant;
            } elseif ($user !== null) {
                $userIds[] = $user;
            }
        }

        $tenantNames = array_map(static fn ($tenant): string => $tenant->name, $this->tenants->findMany(array_values(array_unique($tenantIds))));
        /** @var array<int, string> $staffNames */
        $staffNames = $userIds === [] ? [] : User::query()->whereIn('id', array_unique($userIds))->pluck('name', 'id')->all();
        $name = static fn (AuthorKind $kind, ?int $user, ?int $tenant): string => $kind === AuthorKind::Tenant
            ? AuthorNames::short($tenant !== null ? ($tenantNames[$tenant] ?? '') : '')
            : ($user !== null && isset($staffNames[$user]) ? $staffNames[$user] : __('A former member of staff'));

        $items = [];

        foreach ($reports->groupBy(static fn (GroupReport $report): string => $report->reportable_type->value.':'.$report->reportable_id) as $rows) {
            /** @var GroupReport $first */
            $first = $rows->first();
            $item = $first->reportable_type === ReactableType::Post ? $posts->get($first->reportable_id) : $comments->get($first->reportable_id);

            if (! $item instanceof GroupPost && ! $item instanceof GroupComment) {
                continue;
            }

            $postId = $item instanceof GroupPost ? $item->id : $item->group_post_id;

            if (! $posts->has($postId) && ! GroupPost::query()->moderatable()->where('building_group_id', $group->id)->whereKey($postId)->exists()) {
                continue;
            }

            $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string) $this->richText->clean($item->body))), ENT_QUOTES | ENT_HTML5));

            $items[] = new ReportedItem(
                type: $first->reportable_type,
                id: $first->reportable_id,
                postId: $postId,
                author: $name($item->author_kind, $item->author_user_id, $item->author_tenant_profile_id),
                excerpt: $text === '' ? __('(photos only)') : Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, 160),
                status: $item->status,
                fromTenant: $item->author_kind === AuthorKind::Tenant,
                reports: array_values($rows->map(static fn (GroupReport $report): QueuedReport => new QueuedReport(
                    reason: $report->reason,
                    reporter: $name($report->reporter_kind, $report->reporter_user_id, $report->reporter_tenant_profile_id),
                    note: $report->note,
                    at: $report->created_at,
                ))->all()),
                mine: $item->author_user_id === $viewer->id,
            );
        }

        usort($items, static fn (ReportedItem $a, ReportedItem $b): int => count($b->reports) <=> count($a->reports));

        return $items;
    }
}
