<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Moderation;

use App\Branding\Branding;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupReport;
use Modules\BuildingGroup\Notifications\PostReported;
use Modules\Properties\Contracts\PropertyDirectory;

/**
 * Tells a group's moderators that something in it was reported (P07
 * default 46, product owner 2026-11-11): everyone in the organization the
 * group policy lets moderate it (owners, administrators, and agents who
 * work on the building), not the reporter. Once per item: only its first
 * open report notifies, so an item reported by ten tenants sends one
 * notice. The notice names the building, who reported (a tenant or a
 * member of the team), whether a post or comment, and the reason; never
 * the note or the item's text.
 */
final readonly class ReportNotifier
{
    public function __construct(
        private OrganizationContext $context,
        private PropertyDirectory $buildings,
    ) {}

    public function reported(GroupReport $report, BuildingGroup $group, User $by): int
    {
        $earlier = GroupReport::query()->open()
            ->where('reportable_type', $report->reportable_type)
            ->where('reportable_id', $report->reportable_id)
            ->whereKeyNot($report->id)
            ->exists();

        if ($earlier) {
            return 0;
        }

        $organization = $this->context->get();
        $moderators = $organization->users()->whereKeyNot($by->id)->get()
            ->filter(static fn (User $user): bool => Gate::forUser($user)->allows('moderate', $group))
            ->values();

        if ($moderators->isEmpty()) {
            return 0;
        }

        $building = $this->buildings->find($group->property_id)->name ?? __('Building #:id', ['id' => $group->property_id]);
        $item = $report->reportable_type === ReactableType::Post ? __('post') : __('comment');
        $who = $report->reporter_kind === AuthorKind::Tenant ? __('A tenant') : __('A member of your team');

        Notification::send($moderators, new PostReported(
            organizationId: $organization->id,
            groupId: $group->id,
            subject: __('A :item was reported in the :building group', ['item' => $item, 'building' => $building]),
            line: __(':who reported a :item in the :building group as ":reason".', ['who' => $who, 'item' => $item, 'building' => $building, 'reason' => $report->reason->label()]),
            url: route('workspace.groups.show', ['organization' => $organization->slug, 'group' => $group->id]),
            agency: Branding::fromOrganization($organization)->displayName(),
        ));

        return $moderators->count();
    }
}
