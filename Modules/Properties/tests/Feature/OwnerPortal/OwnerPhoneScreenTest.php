<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/helpers.php';

/*
 * P03-15: the owner portal's pages at 360 px (UX_DESIGN.md § 1: every
 * screen designed at 360 px first, with 44 px touch targets; P03-11b's
 * check). Each page is opened as the owner's browser gets it, then laid
 * out in real Chrome (Gotenberg's screenshot route) at 360 CSS pixels: the
 * page must not scroll sideways, and no visible tap target may be smaller
 * than 44 × 44 px (Tests\Support\NarrowScreen::inspect).
 *
 * Skipped locally only when Gotenberg does not answer (PDF_CHROME_URL); on
 * CI an unreachable Gotenberg fails the test (orchestrator decision 6).
 */

beforeEach(function () {
    Notification::fake();
    ownerWorld();
});

afterEach(fn () => ownerAccessFreshRequest());

/**
 * Lays each page out at 360 px; the pages that scroll sideways or have
 * small targets, with what was measured.
 *
 * @param  array<string, TestResponse>  $pages
 * @return array<string, array{viewport: int, overflow: int, targets: int, small: int}>
 */
function ownerPagesFailingOnPhones(string $gotenberg, array $pages): array
{
    $failing = [];

    foreach ($pages as $name => $page) {
        expect($page->status())->toBe(str_contains($name, 'no longer works') ? 404 : 200, $name);
        $screen = NarrowScreen::inspect($gotenberg, NarrowScreen::standalone((string) $page->getContent()), 360);

        if ($screen['viewport'] !== 360 || $screen['overflow'] !== 0 || $screen['small'] !== 0) {
            $failing[$name] = $screen;
        }
    }

    return $failing;
}

describe('owner pages pass the 360 px check', function () {
    it('lays out every owner portal page on a 360 px screen: no sideways scrolling, 44 px targets', function () {
        $gotenberg = requireGotenberg();
        $nobody = User::factory()->create(['name' => 'Nobody Owns', 'email' => 'nobody.owns@example.com']);

        $failing = ownerPagesFailingOnPhones($gotenberg, [
            'home' => ownerPortalPage($this->ngozi),
            'building' => ownerPortalPage($this->ngozi, "/buildings/{$this->palm->id}"),
            'lease' => ownerPortalPage($this->ngozi, "/leases/{$this->lease->id}"),
            'bill' => ownerPortalPage($this->ngozi, "/bills/{$this->bill->id}"),
            'empty home' => ownerPortalPage($nobody),
        ]);

        expect($failing)->toBe([]);
    });

    it('lays out the owner invitation pages on a 360 px screen', function () {
        $gotenberg = requireGotenberg();
        $bisi = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);
        $create = inviteOwnerByLink($this->owner, $bisi);
        $holder = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Femi', 'last_name' => 'Again', 'email' => 'femi.bakare@example.com']);
        $signIn = inviteOwnerByEmail($this->owner, $holder);

        $pages = [
            'invitation (new account)' => openOwnerInvitation($create),
            'invitation (sign in first)' => openOwnerInvitation($signIn),
            'link that no longer works' => openOwnerInvitation(Str::random(48)),
        ];
        acceptOwnerInvitation($create)->assertRedirect('/owner/welcome');
        $user = User::query()->where('phone', '+2348052223333')->sole();
        ownerAccessFreshRequest();
        $pages['welcome'] = $this->actingAs($user)->get('/owner/welcome');

        expect(ownerPagesFailingOnPhones($gotenberg, $pages))->toBe([]);
    });
});
