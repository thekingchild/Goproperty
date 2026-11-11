<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Modules\BuildingGroup\Enums\AuthorKind;
use Modules\BuildingGroup\Enums\ReportReason;
use Modules\BuildingGroup\Notifications\PostReported;

require_once __DIR__.'/group-world.php';

/*
 * Product owner, 2026-11-11 (docs/plans/P07-subtasks.md default 46): a
 * group's moderators are told by email and in the app when something in
 * it is reported, once per item, never with the report's note or the
 * item's text.
 */

beforeEach(function () {
    Storage::fake('media');
    groupWorld();
    $this->spam = postIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->palmGroup, '<p>Cheap phones for sale, call me.</p>');
    $this->rude = commentIn($this->acme, $this->kemi, AuthorKind::Tenant, $this->spam, '<p>Nobody asked you.</p>');
    Notification::fake();
});

it('tells the group\'s moderators of a report, in the app and by email, without the note or the post', function () {
    reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam, ReportReason::Spam, 'Kemi sells phones, here is her number');

    foreach ([$this->owner, $this->admin, $this->agent] as $moderator) {
        Notification::assertSentTo($moderator, PostReported::class, function (PostReported $notice, array $channels) use ($moderator): bool {
            $mail = $notice->toMail($moderator)->render();

            return $channels === ['database', 'mail']
                && $notice->subject === 'A post was reported in the Palm Court group'
                && $notice->line === 'A tenant reported a post in the Palm Court group as "Spam or advertising".'
                && $notice->url === route('workspace.groups.show', ['organization' => 'acme-homes', 'group' => $this->palmGroup->id])
                && ! str_contains((string) $mail, 'phones') && ! str_contains((string) $mail, 'her number');
        });
    }

    // Not the reporter, the post's author, other tenants or another agency.
    foreach ([$this->tunde, $this->kemi, $this->grace, $this->otherOwner] as $person) {
        Notification::assertNotSentTo($person, PostReported::class);
    }
});

it('sends one notice per item: later reports of the same item add none, another item gets its own', function () {
    reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->spam);
    reportIn($this->acme, $this->agent, AuthorKind::Staff, $this->spam);
    Notification::assertSentToTimes($this->owner, PostReported::class, 1);

    reportIn($this->acme, $this->tunde, AuthorKind::Tenant, $this->rude, ReportReason::Abuse);
    Notification::assertSentTo($this->owner, PostReported::class, fn (PostReported $notice): bool => $notice->line === 'A tenant reported a comment in the Palm Court group as "Abusive, threatening or harassing".');
    Notification::assertSentToTimes($this->owner, PostReported::class, 2);
});

it('says when a member of the team reported it, and never tells the reporter', function () {
    reportIn($this->acme, $this->agent, AuthorKind::Staff, $this->spam);

    Notification::assertSentTo($this->owner, PostReported::class, fn (PostReported $notice): bool => str_starts_with($notice->line, 'A member of your team reported a post'));
    Notification::assertNotSentTo($this->agent, PostReported::class);
});
