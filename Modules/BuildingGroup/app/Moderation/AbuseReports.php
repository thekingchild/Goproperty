<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Moderation;

use App\Models\User;
use App\Plans\Feature;
use App\Plans\PlanGate;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\ReactableType;
use Modules\BuildingGroup\Enums\ReportReason;
use Modules\BuildingGroup\Enums\ReportStatus;
use Modules\BuildingGroup\Groups\GroupMembership;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\BuildingGroup\Models\GroupReport;
use Modules\BuildingGroup\Posts\GroupRefused;

/**
 * The only writer of `group_reports` (ARCHITECTURE.md § 6.8 "How
 * moderation is built"; P07-04, docs/plans/P07-subtasks.md defaults
 * 45–47), in the
 * group's organization's context.
 *
 * A member reports a post or comment they can see (published, and for a
 * reply its comment shown) with a reason and an optional note: tenants
 * from the portal (`reportAsTenant`: a current member who is not muted)
 * and staff who write in the group from the workspace (`report`). Not
 * their own item; one report per person and item, ever (the unique key);
 * 20 reports a day a person, across groups. The report stays open until a
 * moderator hides or removes the item (Moderation\GroupModeration, which
 * resolves the item's open reports here as actioned) or dismisses them.
 * Reporting never hides anything by itself. Audited (log
 * `building_group`, `group.reported`: ids and the reason's key, never the
 * note); open pages are nudged (moderators see the count).
 */
final readonly class AbuseReports
{
    public const int REPORTS_PER_DAY = 20;

    public const int NOTE_MAX = 500;

    public function __construct(
        private GroupMembership $membership,
        private PlanGate $plans,
        private OrganizationContext $context,
        private LiveFeed $live,
    ) {}

    /** @throws ValidationException */
    public function report(User $by, AuthorKind $as, ReactableType $type, int $id, ReportReason $reason, ?string $note = null): GroupReport
    {
        [$post, $item] = $this->shown($type, $id);
        $group = BuildingGroup::query()->find($post->building_group_id)
            ?? throw GroupRefused::because(__('That post is no longer there.'), 'report');
        Gate::forUser($by)->authorize($as === AuthorKind::Staff ? 'report' : 'reportAsTenant', $group);

        if ($item->author_user_id === $by->id) {
            throw GroupRefused::because($type === ReactableType::Post ? __('This is your own post: remove it instead.') : __('This is your own comment: remove it instead.'), 'report');
        }

        $note = self::note($note);
        $key = 'group-reports:'.$by->id;

        if (RateLimiter::tooManyAttempts($key, self::REPORTS_PER_DAY)) {
            throw GroupRefused::because(__('You have sent a lot of reports today. Try again tomorrow, or speak to the agency.'), 'report');
        }

        $report = DB::transaction(function () use ($by, $as, $type, $id, $reason, $note, $group): GroupReport {
            GroupPost::query()->whereKey($this->shown($type, $id)[0]->id)->lockForUpdate()->first();
            $this->plans->ensureFeature(Feature::BuildingGroup, $this->context->get());
            // The item may have been hidden while the post was waiting.
            $this->shown($type, $id);

            if (GroupReport::query()->where('reporter_user_id', $by->id)->where('reportable_type', $type)->where('reportable_id', $id)->exists()) {
                throw GroupRefused::because(__('You reported this already. The agency will look at it.'), 'report');
            }

            $report = new GroupReport;
            $report->building_group_id = $group->id;
            $report->reportable_type = $type;
            $report->reportable_id = $id;
            $report->reporter_user_id = $by->id;
            $report->reporter_kind = $as;
            $report->reporter_tenant_profile_id = $as === AuthorKind::Tenant ? $this->tenantRecord($by, $group) : null;
            $report->reason = $reason;
            $report->note = $note;
            $report->status = ReportStatus::Open;

            try {
                $report->save();
            } catch (UniqueConstraintViolationException) {
                throw GroupRefused::because(__('You reported this already. The agency will look at it.'), 'report');
            }

            activity(GroupModeration::LOG)
                ->event('group.reported')
                ->performedOn($report)
                ->causedBy($by)
                ->withProperties([
                    'building_group_id' => $group->id,
                    'reportable_type' => $type->value,
                    'reportable_id' => $id,
                    'reason' => $reason->value,
                    'reporter' => $as->value,
                    'tenant_profile_id' => $report->reporter_tenant_profile_id,
                ])
                ->log('Post or comment reported in the Building Group');

            $this->live->changed($group->id);

            return $report;
        }, 3);

        RateLimiter::hit($key, 86400);

        return $report;
    }

    /**
     * Marks the item's open reports as $status, by the moderator; the
     * number resolved. Called by Moderation\GroupModeration inside its
     * transaction, with the item's post locked.
     */
    public function resolve(ReactableType $type, int $id, ReportStatus $status, User $by): int
    {
        return GroupReport::query()->open()
            ->where('reportable_type', $type)
            ->where('reportable_id', $id)
            ->update([
                'status' => $status,
                'resolved_by_user_id' => $by->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** @throws ValidationException */
    private static function note(?string $note): ?string
    {
        $note = trim(strip_tags((string) $note));

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw ValidationException::withMessages(['report' => __('Keep the note to :max characters.', ['max' => self::NOTE_MAX])]);
        }

        return $note === '' ? null : $note;
    }

    /**
     * The post and the item (the post, or the comment) as members see them.
     *
     * @return array{0: GroupPost, 1: GroupPost|GroupComment}
     */
    private function shown(ReactableType $type, int $id): array
    {
        if ($type === ReactableType::Post) {
            $post = GroupPost::query()->published()->find($id);

            return $post instanceof GroupPost ? [$post, $post] : throw GroupRefused::because(__('That post is no longer there.'), 'report');
        }

        $comment = GroupComment::query()->published()->find($id);
        $post = $comment instanceof GroupComment ? GroupPost::query()->published()->find($comment->group_post_id) : null;
        $parentShown = $comment instanceof GroupComment && ($comment->parent_id === null
            || GroupComment::query()->where('status', PostStatus::Published)->whereKey($comment->parent_id)->exists());

        if (! $comment instanceof GroupComment || ! $post instanceof GroupPost || ! $parentShown) {
            throw GroupRefused::because(__('That comment is no longer there.'), 'report');
        }

        return [$post, $comment];
    }

    private function tenantRecord(User $by, BuildingGroup $group): int
    {
        $this->membership->forget();

        return $this->membership->tenantProfileFor($by, $group)
            ?? throw GroupRefused::because(__('You are no longer a member of this group.'), 'report');
    }
}
