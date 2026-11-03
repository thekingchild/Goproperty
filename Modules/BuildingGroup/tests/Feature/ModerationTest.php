<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationStatus;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\BlockedWhileImpersonating;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ModerationAction;
use Modules\BuildingGroup\Enums\MuteLength;
use Modules\BuildingGroup\Enums\PostStatus;
use Modules\BuildingGroup\Enums\Reaction;
use Modules\BuildingGroup\Enums\ReportReason;
use Modules\BuildingGroup\Enums\ReportStatus;
use Modules\BuildingGroup\Groups\BuildingGroups;
use Modules\BuildingGroup\Lists\GroupList;
use Modules\BuildingGroup\Live\LiveFeed;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupMember;
use Modules\BuildingGroup\Models\GroupModerationEvent;
use Modules\BuildingGroup\Models\GroupReport;
use Modules\BuildingGroup\Moderation\AbuseReports;
use Modules\BuildingGroup\Policies\BuildingGroupPolicy;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/group-world.php';
require_once __DIR__.'/../../../../tests/Support/plans.php';

/*
 * P07-04: moderation and reports (Moderation\GroupModeration,
 * Moderation\AbuseReports; ARCHITECTURE.md § 6.8 "How moderation is
 * built"; docs/plans/P07-subtasks.md defaults 39–52). Moderators are the
 * agency's staff holding `building_groups.moderate` (owners, administrators,
 * agents in their buildings); tenants report. Hidden and removed posts and
 * comments vanish for members, their authors included, and stay for
 * moderators with their trail (group_moderation_events and the audit log).
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
    $this->welcome = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, '<p>Welcome to Palm Court\'s group.</p>');
    $this->spam = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '<p>Cheap phones for sale, call me.</p>');
    $this->rant = postIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->palmGroup, '<p>Flat 3B is full of thieves.</p>');
    $this->rude = commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->welcome, '<p>Nobody asked you.</p>');
    $this->kind = commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->welcome, '<p>Thank you, Bola.</p>');
});

afterEach(fn () => groupsFreshRequest());

/** Opens the group's page as $user (portal for tenants, workspace for staff): its snapshot. */
function openGroupPage(mixed $user, string $side = 'portal'): string
{
    $page = $side === 'portal' ? portalGroupPage($user, test()->palmGroup) : workspaceGroupPage($user, test()->palmGroup);

    return LivewireHttp::snapshot($page->assertOk());
}

/** One Livewire request from $user's session on the page whose snapshot is $snapshot. */
function onGroupPage(mixed $user, string $snapshot, array $updates = [], array $calls = [['refreshFeed']]): TestResponse
{
    groupsFreshRequest();
    test()->actingAs($user);

    return LivewireHttp::update(test(), $snapshot, $updates, $calls);
}

/** The ids of the posts a page shows, in order. */
function postIdsOn(TestResponse|string $page): array
{
    preg_match_all('/data-test="post" data-post="(\d+)"/', is_string($page) ? $page : htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

/** The HTML of one post on a page, up to the end of its article. */
function postHtml(string $html, int $postId): string
{
    $start = strpos($html, 'data-test="post" data-post="'.$postId.'"');
    expect($start)->not->toBeFalse();

    return substr($html, (int) $start, strpos($html, '</article>', (int) $start) - (int) $start);
}

/** A post's own moderation buttons (the first action row in its article; its comments' come after). */
function postActions(string $html, int $postId): string
{
    $post = postHtml($html, $postId);
    $start = strpos($post, 'data-test="moderation-actions"');
    expect($start)->not->toBeFalse();

    return substr($post, (int) $start, strpos($post, '</div>', (int) $start) - (int) $start);
}

/** The ids of the comments and replies a page shows. */
function commentIdsOn(TestResponse|string $page): array
{
    preg_match_all('/data-test="(?:comment|reply)" data-comment="(\d+)"/', is_string($page) ? $page : htmlOf($page), $matches);

    return array_map('intval', $matches[1]);
}

it('acceptance: hidden and removed posts vanish for members but remain for moderators with an audit trail', function () {
    // Two tenants' pages are open in their browsers.
    $kemisPage = openGroupPage($this->kemi);
    $tundesPage = openGroupPage($this->tunde);
    expect(postIdsOn(portalGroupPage($this->tunde, $this->palmGroup)))->toContain($this->spam->id, $this->rant->id);

    $this->travel(1)->minutes();
    hideIn($this->acme, $this->admin, $this->spam, 'Selling things is not allowed here.');
    removeIn($this->acme, $this->agent, $this->rant, 'Accuses neighbours of a crime.');
    hideIn($this->acme, $this->admin, $this->rude, 'Rude to a member of staff.');

    // Members, the authors included: gone at the next refresh and on a fresh page.
    $this->travel(15)->seconds();
    foreach ([[$this->kemi, $kemisPage], [$this->tunde, $tundesPage]] as [$member, $snapshot]) {
        $html = LivewireHttp::html(onGroupPage($member, $snapshot)->assertOk());
        expect(postIdsOn($html))->toBe([$this->welcome->id])
            ->and(commentIdsOn($html))->toBe([$this->kind->id])
            ->and($html)->not->toContain('Cheap phones', 'thieves', 'Nobody asked you', 'Selling things is not allowed', 'moderation-trail');

        portalGroupPage($member, $this->palmGroup)->assertOk()
            ->assertSee('Welcome to Palm Court')->assertSee('Thank you, Bola.')
            ->assertDontSee('Cheap phones')->assertDontSee('thieves')->assertDontSee('Nobody asked you')->assertDontSee('data-status="hidden"', false);
    }

    // Nobody writes on what members cannot see.
    expect(fn () => commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, 'How much?'))->toThrow(ValidationException::class, 'That post is no longer there.')
        ->and(fn () => reactIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, Reaction::Laugh))->toThrow(ValidationException::class)
        ->and(fn () => commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->welcome, 'Agreed', $this->rude->id))->toThrow(ValidationException::class)
        ->and(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam))->toThrow(ValidationException::class);

    // Moderators (the owner, the administrator, the building's agent) still see all three, marked, with who, when and why.
    foreach ([$this->owner, $this->admin, $this->agent] as $moderator) {
        $page = workspaceGroupPage($moderator, $this->palmGroup)->assertOk();
        expect(postIdsOn($page))->toEqualCanonicalizing([$this->welcome->id, $this->spam->id, $this->rant->id])
            ->and(commentIdsOn($page))->toEqualCanonicalizing([$this->rude->id, $this->kind->id]);
        $page->assertSee('data-post="'.$this->spam->id.'" data-status="hidden"', false)
            ->assertSee('data-post="'.$this->rant->id.'" data-status="removed"', false)
            ->assertSee('data-comment="'.$this->rude->id.'" data-status="hidden"', false)
            ->assertSee('Cheap phones for sale')->assertSee('Flat 3B is full of thieves.')->assertSee('Nobody asked you.')
            ->assertSee('Hidden: members do not see this post or its comments.')
            ->assertSee('Removed: members do not see this post or its comments, and it cannot be shown again.')
            ->assertSeeInOrder(['Hidden', 'by Seyi Admin, 15 Mar 2027, 11:01', 'Selling things is not allowed here.'])
            ->assertSeeInOrder(['Removed', 'by Bola Agent, 15 Mar 2027, 11:01', 'Accuses neighbours of a crime.'])
            ->assertSee('Rude to a member of staff.');
    }

    // The trail: one row per change with who, what and why, and an audit entry pointing to it (ids only, never the words).
    $events = moderationEvents($this->acme);
    expect(array_map(fn (GroupModerationEvent $event): array => [$event->action, $event->target_id, $event->actor_user_id, $event->reason], $events))->toBe([
        [ModerationAction::Hide, $this->spam->id, $this->admin->id, 'Selling things is not allowed here.'],
        [ModerationAction::Remove, $this->rant->id, $this->agent->id, 'Accuses neighbours of a crime.'],
        [ModerationAction::Hide, $this->rude->id, $this->admin->id, 'Rude to a member of staff.'],
    ]);
    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->whereIn('event', ['group.post_hidden', 'group.post_removed', 'group.comment_hidden'])->orderBy('id')->get());
    expect($audit->pluck('event')->all())->toBe(['group.post_hidden', 'group.post_removed', 'group.comment_hidden'])
        ->and($audit->pluck('causer_id')->all())->toBe([$this->admin->id, $this->agent->id, $this->admin->id])
        ->and($audit->map(fn (AuditEntry $entry) => $entry->properties['group_moderation_event_id'])->all())->toBe(array_map(fn ($event) => $event->id, $events))
        ->and($audit[0]->properties['from'])->toBe('published')->and($audit[0]->properties['to'])->toBe('hidden')
        ->and(json_encode($audit->pluck('properties')->all()))->not->toContain('Selling things', 'Cheap phones');
    expect(fn () => inOrganization($this->acme, fn () => $events[0]->forceFill(['reason' => 'changed'])->save()))->toThrow(LogicException::class)
        ->and(fn () => inOrganization($this->acme, fn () => $events[0]->delete()))->toThrow(LogicException::class);

    // Nothing is deleted: the rows are there, only their status moved; the comment count is what members see.
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Hidden)
        ->and(storedPost($this->acme, $this->rant)->status)->toBe(PostStatus::Removed)
        ->and(storedPost($this->acme, $this->rant)->deleted_at)->toBeNull()
        ->and(storedPost($this->acme, $this->welcome)->comment_count)->toBe(1);

    // Hiding is undone by showing it again; removing is final.
    unhideIn($this->acme, $this->owner, $this->spam);
    portalGroupPage($this->tunde, $this->palmGroup)->assertSee('Cheap phones for sale');
    expect(fn () => unhideIn($this->acme, $this->owner, $this->rant))->toThrow(ValidationException::class, 'A removed post or comment cannot be shown again.');
    portalGroupPage($this->tunde, $this->palmGroup)->assertDontSee('thieves');
    workspaceGroupPage($this->owner, $this->palmGroup)->assertSeeInOrder(['Hidden', 'Seyi Admin', 'Shown again', 'by Ada Owner']);
});

it('takes a hidden comment\'s replies away with it, and keeps hiding and removing idempotent', function () {
    $reply = commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->welcome, '<p>Calm down, Kemi.</p>', $this->rude->id);
    hideIn($this->acme, $this->owner, $this->rude);

    expect(commentIdsOn(portalGroupPage($this->tunde, $this->palmGroup)))->toBe([$this->kind->id])
        ->and(commentIdsOn(workspaceGroupPage($this->owner, $this->palmGroup)))->toEqualCanonicalizing([$this->rude->id, $reply->id, $this->kind->id]);

    // Asking again for what is already so writes nothing.
    hideIn($this->acme, $this->owner, $this->rude);
    removeIn($this->acme, $this->owner, $this->rude);
    removeIn($this->acme, $this->owner, $this->rude);
    pinIn($this->acme, $this->owner, $this->welcome);
    pinIn($this->acme, $this->owner, $this->welcome);
    expect(array_map(fn ($event) => $event->action, moderationEvents($this->acme)))->toBe([ModerationAction::Hide, ModerationAction::Remove, ModerationAction::Pin]);

    // A reason is needed, and kept short.
    expect(fn () => hideIn($this->acme, $this->owner, $this->spam, '  '))->toThrow(ValidationException::class, 'Say why, for the record.')
        ->and(fn () => removeIn($this->acme, $this->owner, $this->spam, null))->toThrow(ValidationException::class, 'Say why')
        ->and(fn () => hideIn($this->acme, $this->owner, $this->spam, str_repeat('a', 501)))->toThrow(ValidationException::class, 'Keep the reason to 500 characters.');
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Published);
});

it('pins posts to the top for everyone, at most three, and a hidden post loses its pin', function () {
    $this->travel(1)->minutes();
    $later = postIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->palmGroup, '<p>Newest post.</p>');
    expect(postIdsOn(portalGroupPage($this->kemi, $this->palmGroup)))->toBe([$later->id, $this->rant->id, $this->spam->id, $this->welcome->id]);

    pinIn($this->acme, $this->agent, $this->welcome);
    $this->travel(1)->minutes();
    pinIn($this->acme, $this->agent, $this->spam);

    // The newest pin first, then the rest by activity; everyone sees it.
    expect(postIdsOn(portalGroupPage($this->kemi, $this->palmGroup)))->toBe([$this->spam->id, $this->welcome->id, $later->id, $this->rant->id])
        ->and(postIdsOn(workspaceGroupPage($this->owner, $this->palmGroup)))->toBe([$this->spam->id, $this->welcome->id, $later->id, $this->rant->id]);
    portalGroupPage($this->kemi, $this->palmGroup)->assertSee('data-test="pinned"', false);

    pinIn($this->acme, $this->agent, $this->rant);
    expect(fn () => pinIn($this->acme, $this->agent, $later))->toThrow(ValidationException::class, 'A group can have 3 pinned posts. Unpin one first.');

    hideIn($this->acme, $this->agent, $this->spam);
    expect(storedPost($this->acme, $this->spam)->pinned_at)->toBeNull()
        ->and(moderationEvents($this->acme, $this->spam)[1]->details)->toEqual(['from' => 'published', 'to' => 'hidden', 'unpinned' => true]);
    pinIn($this->acme, $this->agent, $later);
    expect(fn () => pinIn($this->acme, $this->agent, $this->spam))->toThrow(ValidationException::class, 'Only a post members can see can be pinned.');

    pinIn($this->acme, $this->agent, $this->welcome, false);
    expect(storedPost($this->acme, $this->welcome)->pinned_at)->toBeNull();
    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->whereIn('event', ['group.post_pinned', 'group.post_unpinned'])->pluck('event')->all());
    expect($audit)->toBe(['group.post_pinned', 'group.post_pinned', 'group.post_pinned', 'group.post_pinned', 'group.post_unpinned']);
});

it('mutes a tenant: they read, told until when, and write nothing until it ends; staff are never muted', function () {
    muteIn($this->acme, $this->admin, $this->palmGroup, $this->kemiAtAcme->id, MuteLength::Week, 'Repeated rude comments.');
    $until = now()->addDays(7);

    portalGroupPage($this->kemi, $this->palmGroup)->assertOk()
        ->assertSee('Adeyemi Realty Ltd has paused your posting here until 22 Mar 2027, 11:00. You can still read the group.')
        ->assertSee('Welcome to Palm Court')
        ->assertDontSee('data-test="post-form"', false)->assertDontSee('data-test="open-comment"', false)->assertDontSee('data-test="report"', false);
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->welcome, 'Hello'))->toThrow(AuthorizationException::class)
        ->and(fn () => reactIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->welcome, Reaction::Heart))->toThrow(AuthorizationException::class)
        ->and(fn () => reportIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->rant))->toThrow(AuthorizationException::class);
    // Tunde is not muted.
    commentIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->welcome, 'Still here.');

    // Moderators see who is muted until when, and the trail.
    workspaceGroupPage($this->owner, $this->palmGroup)->assertSee('data-test="muted-members"', false)->assertSeeInOrder(['Muted tenants', 'Kemi Adebayo', 'until 22 Mar 2027, 11:00']);
    $member = memberRow($this->acme, $this->palm, $this->kemiAtAcme);
    expect($member->muted_until?->toDateTimeString())->toBe($until->toDateTimeString())
        ->and(moderationEvents($this->acme)[0]->only(['action', 'target_id', 'reason']))->toEqual(['action' => ModerationAction::MuteMember, 'target_id' => $member->id, 'reason' => 'Repeated rude comments.']);

    // Unmuting ends it now.
    inOrganization($this->acme, fn () => groupModeration()->unmute($this->owner, $this->palmGroup, $member->id));
    commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->welcome, 'Sorry, everyone.');
    portalGroupPage($this->kemi, $this->palmGroup)->assertDontSee('data-test="portal-muted"', false);

    // A mute also ends by itself; it stays with a tenant who leaves and comes back (the sync never touches it).
    muteIn($this->acme, $this->admin, $this->palmGroup, $this->kemiAtAcme->id, MuteLength::Day);
    groupsFreshRequest();
    inOrganization($this->acme, fn () => app(BuildingGroups::class)->sync($this->palm->id));
    expect(memberRow($this->acme, $this->palm, $this->kemiAtAcme)->muted_until)->not->toBeNull();
    $this->travel(25)->hours();
    commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->welcome, 'Back.');

    // Members of staff are not muted: they hold no member row.
    $snapshot = openGroupPage($this->owner, 'workspace');
    $page = onGroupPage($this->owner, $snapshot, [], [['startModeration', ['mute', 'group_post', $this->welcome->id]]])->assertOk();
    $page = onGroupPage($this->owner, LivewireHttp::next($page), ['reason' => 'Test'], [['confirmModeration']])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Only a tenant of the building can be muted.');
    expect(inOrganization($this->acme, fn () => GroupMember::query()->where('muted_until', '>', now())->count()))->toBe(0)
        ->and(fn () => inOrganization($this->acme, fn () => groupModeration()->mute($this->owner, $this->palmGroup, 999_999, MuteLength::Day, 'x')))->toThrow(ValidationException::class, 'That tenant is no longer a member of this group.');
});

it('lets members report someone else\'s post or comment once; moderators see it, act on it or dismiss it', function () {
    $report = reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, ReportReason::Spam, 'She posts this every day.');
    reportIn($this->acme, $this->owner, AuthorKind::Staff, $this->spam, ReportReason::Spam);
    reportIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->rant, ReportReason::Abuse, '<b>Lies</b> about my flat');

    expect($report->only(['reporter_kind', 'reporter_tenant_profile_id', 'reason', 'note', 'status']))->toEqual([
        'reporter_kind' => AuthorKind::Tenant, 'reporter_tenant_profile_id' => $this->tundeAtAcme->id, 'reason' => ReportReason::Spam,
        'note' => 'She posts this every day.', 'status' => ReportStatus::Open,
    ])->and(inOrganization($this->acme, fn () => GroupReport::query()->where('reporter_user_id', $this->kemi->id)->value('note')))->toBe('Lies about my flat');

    // Reporting hides nothing; the reporter sees they reported it, nobody else sees reports.
    portalGroupPage($this->tunde, $this->palmGroup)->assertSee('Cheap phones')->assertSee('You reported this');
    portalGroupPage($this->kemi, $this->palmGroup)->assertSee('Cheap phones')->assertDontSee('She posts this every day.')->assertDontSee('data-test="open-reports"', false);

    // Not one's own, not twice, not something members cannot see.
    expect(fn () => reportIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->spam))->toThrow(ValidationException::class, 'This is your own post: remove it instead.')
        ->and(fn () => reportIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->rude))->toThrow(ValidationException::class, 'This is your own comment')
        ->and(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, ReportReason::Abuse))->toThrow(ValidationException::class, 'You reported this already.')
        ->and(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, ReportReason::Abuse, str_repeat('a', 501)))->toThrow(ValidationException::class, 'Keep the note to 500 characters.')
        ->and(fn () => reportIn($this->acme, $this->grace, AuthorKind::Tenant, $this->spam))->toThrow(AuthorizationException::class);

    // The moderators' queue: the most reported first, who and why, an excerpt; a count on the list and its export.
    $page = workspaceGroupPage($this->admin, $this->palmGroup)->assertOk()->assertSee('data-test="report-queue"', false);
    $page->assertSeeInOrder(['Post by Kemi A.', '2 reports', 'Cheap phones for sale, call me.', 'Spam or advertising', 'Tunde B.', 'She posts this every day.', 'Spam or advertising', 'Ada Owner', 'Post by Tunde B.', '1 report', 'Abusive, threatening or harassing', 'Kemi A.', 'Lies about my flat']);
    workspaceGroupPage($this->admin)->assertSee('3 reports to look at');
    $listed = inOrganization($this->acme, function () {
        $list = app(GroupList::class);

        return [$list->query($this->admin, [])->whereKey($this->palmGroup->id)->sole()->getAttribute('open_reports_count'), array_map(fn ($column) => $column->header, $list->columns($this->admin))];
    });
    expect((int) $listed[0])->toBe(3)->and($listed[1])->toContain('Open reports');

    // Acting on it closes its reports as actioned; dismissing closes them with nothing changed.
    hideIn($this->acme, $this->admin, $this->spam);
    inOrganization($this->acme, fn () => groupModeration()->dismissReports($this->admin, itemType($this->rant), $this->rant->id));
    $statuses = inOrganization($this->acme, fn () => GroupReport::query()->orderBy('id')->get(['status', 'resolved_by_user_id'])->map(fn ($row) => [$row->status, $row->resolved_by_user_id])->all());
    expect($statuses)->toBe([[ReportStatus::Actioned, $this->admin->id], [ReportStatus::Actioned, $this->admin->id], [ReportStatus::Dismissed, $this->admin->id]])
        ->and(storedPost($this->acme, $this->rant)->status)->toBe(PostStatus::Published)
        ->and(moderationEvents($this->acme, $this->spam)[0]->details)->toEqual(['from' => 'published', 'to' => 'hidden', 'reports_actioned' => 2]);
    workspaceGroupPage($this->admin, $this->palmGroup)->assertSee('Nothing reported.');
    workspaceGroupPage($this->admin)->assertDontSee('to look at');

    // Audited with ids and the reason's key, never the note.
    $audit = inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.reported')->orderBy('id')->get());
    expect($audit)->toHaveCount(3)->and($audit[0]->properties['reason'])->toBe('spam')
        ->and(json_encode($audit->pluck('properties')->all()))->not->toContain('every day');
    expect(inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.reports_dismissed')->count()))->toBe(1);

    // 20 reports a day a person.
    foreach (range(1, AbuseReports::REPORTS_PER_DAY - 1) as $n) {
        $post = postIn($this->acme, $this->agent, AuthorKind::Staff, $this->palmGroup, "<p>Notice {$n}</p>");
        if ($n === 9) {
            $this->travel(1)->hours();
        }
        reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $post, ReportReason::Other);
    }
    $last = postIn($this->acme, $this->owner, AuthorKind::Staff, $this->palmGroup, '<p>One more.</p>');
    expect(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $last, ReportReason::Other))->toThrow(ValidationException::class, 'You have sent a lot of reports today.');
});

it('reports and moderates through the pages: the report form, the moderator\'s reason form, pins and settings', function () {
    // Tunde reports Kemi's post from the portal.
    $snapshot = openGroupPage($this->tunde);
    $page = onGroupPage($this->tunde, $snapshot, [], [['startReport', ['group_post', $this->spam->id]]])->assertOk();
    expect(LivewireHttp::html($page))->toContain('data-test="report-form"', 'Why are you reporting it?', 'Spam or advertising');
    $page = onGroupPage($this->tunde, LivewireHttp::next($page), [], [['sendReport']]);
    expect(LivewireHttp::html($page))->toContain('Choose why you are reporting it.');
    $page = onGroupPage($this->tunde, LivewireHttp::next($page), ['reportReason' => 'spam', 'reportNote' => 'Every day.'], [['sendReport']])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Thank you. The agency will look at it', 'You reported this')->not->toContain('data-test="report-form"');
    expect(inOrganization($this->acme, fn () => GroupReport::query()->sole()->only(['reportable_id', 'reason', 'note'])))->toEqual(['reportable_id' => $this->spam->id, 'reason' => ReportReason::Spam, 'note' => 'Every day.']);
    // Own posts have no Report button; others' do.
    $kemis = htmlOf(portalGroupPage($this->kemi, $this->palmGroup));
    expect(postHtml($kemis, $this->spam->id))->not->toContain('data-test="report"')
        ->and(postHtml($kemis, $this->rant->id))->toContain('data-test="report"');

    // Seyi hides it from the queue: the reason form, then the post is hidden.
    $snapshot = openGroupPage($this->admin, 'workspace');
    $page = onGroupPage($this->admin, $snapshot, [], [['startModeration', ['hide', 'group_post', $this->spam->id, 'queue']]])->assertOk();
    expect(substr_count(LivewireHttp::html($page), 'data-test="moderation-form"'))->toBe(1);
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['confirmModeration']]);
    expect(LivewireHttp::html($page))->toContain('Say why, for the record. Members do not see it.');
    $page = onGroupPage($this->admin, LivewireHttp::next($page), ['reason' => 'Advertising.'], [['confirmModeration']])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Hidden. Members no longer see it; you can show it again.', 'Nothing reported.', 'Advertising.');
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Hidden);

    // Removing a comment from the feed, pinning, showing again.
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['startModeration', ['remove', 'group_comment', $this->rude->id]]])->assertOk();
    $page = onGroupPage($this->admin, LivewireHttp::next($page), ['reason' => 'Rude.'], [['confirmModeration']])->assertOk();
    expect(inOrganization($this->acme, fn () => GroupComment::query()->find($this->rude->id)->status))->toBe(PostStatus::Removed);
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['pin', [$this->welcome->id]]])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Pinned to the top of the group.');
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['unhide', ['group_post', $this->spam->id]]])->assertOk();
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Published);

    // Mute Kemi from her post, for a day.
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['startModeration', ['mute', 'group_post', $this->spam->id]]])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Mute its author', '1 day', '30 days');
    $page = onGroupPage($this->admin, LivewireHttp::next($page), ['reason' => 'Cooling off.', 'muteDays' => 1], [['confirmModeration']])->assertOk();
    expect(memberRow($this->acme, $this->palm, $this->kemiAtAcme)->muted_until?->toDateTimeString())->toBe(now()->addDay()->toDateTimeString());
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['unmute', [memberRow($this->acme, $this->palm, $this->kemiAtAcme)->id]]])->assertOk();
    expect(memberRow($this->acme, $this->palm, $this->kemiAtAcme)->muted_until)->toBeNull();

    // Settings: tenants no longer start posts, no photos.
    $page = onGroupPage($this->admin, LivewireHttp::next($page), ['settingMembersPost' => false, 'settingImages' => false], [['saveSettings']])->assertOk();
    expect(LivewireHttp::html($page))->toContain('Group settings saved.');
    expect(groupOf($this->acme, $this->palm)->settings)->toEqual(['members_post' => false, 'images' => false])
        ->and(moderationEvents($this->acme)[array_key_last(moderationEvents($this->acme))]->details)->toEqual(['before' => ['members_post' => true, 'images' => true], 'after' => ['members_post' => false, 'images' => false]]);
    portalGroupPage($this->kemi, $this->palmGroup)->assertDontSee('data-test="post-form"', false);
    expect(fn () => postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, 'Hi'))->toThrow(AuthorizationException::class, 'only the agency starts posts');

    // An item of another group is not this page's to act on.
    $oakPost = postIn($this->acme, $this->owner, AuthorKind::Staff, $this->oakGroup, '<p>Oak news.</p>');
    $page = onGroupPage($this->admin, LivewireHttp::next($page), [], [['pin', [$oakPost->id]]]);
    expect(LivewireHttp::html($page))->toContain('That post is no longer there.');
    expect(storedPost($this->acme, $oakPost)->pinned_at)->toBeNull();
});

it('leaves moderation to the agency\'s staff in their buildings: never tenants, nobody across organizations', function () {
    // Tenants never moderate, whatever side they ask from.
    expect(fn () => hideIn($this->acme, $this->tunde, $this->spam))->toThrow(AuthorizationException::class)
        ->and(fn () => pinIn($this->acme, $this->kemi, $this->spam))->toThrow(AuthorizationException::class)
        ->and(fn () => muteIn($this->acme, $this->kemi, $this->palmGroup, $this->tundeAtAcme->id))->toThrow(AuthorizationException::class);
    portalGroupPage($this->kemi, $this->palmGroup)->assertDontSee('data-test="moderation-actions"', false)->assertDontSee('data-test="moderation-panel"', false);
    $snapshot = openGroupPage($this->tunde);
    expect(onGroupPage($this->tunde, $snapshot, [], [['startModeration', ['hide', 'group_post', $this->spam->id]], ['confirmModeration']])->status())->toBeGreaterThanOrEqual(400);
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Published);

    // An agent with no buildings assigned works on all of them; an assigned agent on theirs only; another organization reaches nothing.
    hideIn($this->acme, $this->freeAgent, $this->spam);
    unhideIn($this->acme, $this->owner, $this->spam);
    $oakPost = postIn($this->acme, $this->owner, AuthorKind::Staff, $this->oakGroup, '<p>Oak news.</p>');
    expect(fn () => hideIn($this->acme, $this->agent, $oakPost))->toThrow(AuthorizationException::class)
        ->and(fn () => hideIn($this->other, $this->otherOwner, $this->spam))->toThrow(ValidationException::class, 'That post is no longer there.')
        ->and(fn () => hideIn($this->acme, $this->otherOwner, $this->spam))->toThrow(AuthorizationException::class);
    workspaceGroupPage($this->agent, $this->oakGroup)->assertNotFound();

    // Moderators act rather than report (every default staff role moderates); on their own items they remove as the author, so no hide or remove there.
    $agentsPage = htmlOf(workspaceGroupPage($this->agent, $this->palmGroup)->assertSee('data-test="moderation-panel"', false)->assertDontSee('data-test="report"', false));
    expect(postActions($agentsPage, $this->welcome->id))->toContain('data-test="pin"')->not->toContain('data-test="hide"', 'data-test="remove"')
        ->and(postHtml($agentsPage, $this->welcome->id))->toContain('data-test="delete-post"')
        ->and(postActions($agentsPage, $this->spam->id))->toContain('data-test="hide"', 'data-test="remove"', 'data-test="mute"');
});

it('keeps moderation to open groups of active organizations with Building Group', function () {
    hideIn($this->acme, $this->owner, $this->spam);

    // Suspended: read-only. Moderating is a write permission, which the central guard takes away while suspended, so staff see what members see until the organization is active again.
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();
    workspaceGroupPage($this->owner, $this->palmGroup)->assertOk()->assertSee('Welcome to Palm Court')->assertDontSee('Cheap phones')->assertDontSee('data-test="moderation-panel"', false);
    expect(fn () => unhideIn($this->acme, $this->owner, $this->spam))->toThrow(AuthorizationException::class)
        ->and(fn () => removeIn($this->acme, $this->owner, $this->rant))->toThrow(AuthorizationException::class)
        ->and(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->rant))->toThrow(AuthorizationException::class);
    $this->acme->forceFill(['status' => OrganizationStatus::Active])->save();
    groupsFreshRequest();
    workspaceGroupPage($this->owner, $this->palmGroup)->assertSee('data-status="hidden"', false)->assertSee('Not for this group');

    // A closed group: staff read it, nobody moderates it.
    inOrganization($this->acme, fn () => $this->palmGroup->forceFill(['is_active' => false, 'closed_at' => now()])->save());
    groupsFreshRequest();
    workspaceGroupPage($this->owner, $this->palmGroup)->assertOk()->assertSee('data-status="hidden"', false)->assertDontSee('data-test="hide"', false);
    expect(fn () => removeIn($this->acme, $this->owner, $this->rant))->toThrow(AuthorizationException::class, 'This group is closed')
        ->and(fn () => pinIn($this->acme, $this->owner, $this->welcome))->toThrow(AuthorizationException::class);
    inOrganization($this->acme, fn () => $this->palmGroup->forceFill(['is_active' => true, 'closed_at' => null])->save());

    // A plan without Building Group: nothing.
    startTrial($this->acme, 'starter');
    groupsFreshRequest();
    expect(fn () => removeIn($this->acme, $this->owner, $this->rant))->toThrow(AuthorizationException::class)
        ->and(fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->rant))->toThrow(AuthorizationException::class);
    expect(storedPost($this->acme, $this->rant)->status)->toBe(PostStatus::Published);
});

it('lets impersonating staff in write mode hide and pin, never remove or mute', function () {
    seedStaffRoles();
    $staff = staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']);
    enterSupport(startSupport($staff, $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');

    $page = asSupport()->get("/o/acme-homes/groups/{$this->palmGroup->id}")->assertOk()
        ->assertSee('data-test="hide"', false)->assertDontSee('data-test="remove"', false)->assertDontSee('data-test="mute"', false);

    freshRequest();
    $page = LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['startModeration', ['hide', 'group_post', $this->spam->id]]])->assertOk();
    freshRequest();
    $page = LivewireHttp::update(test(), LivewireHttp::next($page), ['reason' => 'Spam, support.'], [['confirmModeration']])->assertOk();
    expect(storedPost($this->acme, $this->spam)->status)->toBe(PostStatus::Hidden);
    $hidden = inOrganization($this->acme, fn () => AuditEntry::query()->where('event', 'group.post_hidden')->sole());
    expect($hidden->impersonator_staff_id)->toBe($staff->id);

    freshRequest();
    $page = LivewireHttp::update(test(), LivewireHttp::next($page), [], [['startModeration', ['remove', 'group_post', $this->rant->id]]])->assertOk();
    freshRequest();
    LivewireHttp::update(test(), LivewireHttp::next($page), ['reason' => 'Abuse.'], [['confirmModeration']])->assertForbidden();
    expect(storedPost($this->acme, $this->rant)->status)->toBe(PostStatus::Published);

    // Removing is a remove… ability, muting is marked: ImpersonationGuard refuses both.
    expect((new ReflectionMethod(BuildingGroupPolicy::class, 'mute'))->getAttributes(BlockedWhileImpersonating::class))->not->toBeEmpty()
        ->and(method_exists(BuildingGroupPolicy::class, 'removeContent'))->toBeTrue();
});

it('nudges open pages after every moderation write, and never after a refused one', function () {
    $versions = [app(LiveFeed::class)->version($this->palmGroup->id)];
    $steps = [
        fn () => hideIn($this->acme, $this->owner, $this->spam),
        fn () => unhideIn($this->acme, $this->owner, $this->spam),
        fn () => removeIn($this->acme, $this->owner, $this->rant),
        fn () => pinIn($this->acme, $this->owner, $this->welcome),
        fn () => pinIn($this->acme, $this->owner, $this->welcome, false),
        fn () => muteIn($this->acme, $this->owner, $this->palmGroup, $this->kemiAtAcme->id),
        fn () => reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam),
        fn () => inOrganization($this->acme, fn () => groupModeration()->dismissReports($this->owner, itemType($this->spam), $this->spam->id)),
        fn () => inOrganization($this->acme, fn () => groupModeration()->changeSettings($this->owner, $this->palmGroup, false, true)),
    ];

    foreach ($steps as $step) {
        $step();
        $versions[] = app(LiveFeed::class)->version($this->palmGroup->id);
    }

    expect(array_unique($versions))->toHaveCount(count($steps) + 1);

    $before = app(LiveFeed::class)->version($this->palmGroup->id);
    expect(fn () => unhideIn($this->acme, $this->owner, $this->rant))->toThrow(ValidationException::class);
    expect(app(LiveFeed::class)->version($this->palmGroup->id))->toBe($before);
});

it('keeps moderation events and reports in their organization', function () {
    hideIn($this->acme, $this->owner, $this->spam);
    reportIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->rant);

    expect(inOrganization($this->other, fn () => [GroupModerationEvent::query()->count(), GroupReport::query()->count()]))->toBe([0, 0])
        ->and(inOrganization($this->acme, fn () => [GroupModerationEvent::query()->count(), GroupReport::query()->count()]))->toBe([1, 1])
        ->and(fn () => inOrganization($this->acme, fn () => GroupReport::query()->sole()->delete()))->toThrow(LogicException::class);
});
