<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Payments\Payments\PaymentService;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\NarrowScreen;

require_once __DIR__.'/portal-world.php';
require_once __DIR__.'/../../../../Payments/tests/Feature/proof-helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * P03-11b acceptance: the portal is usable at 360 px width (UX_DESIGN.md
 * § 1: tenants use low-end Android phones; every tenant screen is designed
 * at 360 px first, with 44 px touch targets). Every portal page is opened
 * as the tenant's browser gets it, then laid out in real Chrome
 * (Gotenberg's screenshot route, as P03-05b's pay page check) at 360 CSS
 * pixels: the page must not scroll sideways, and no visible tap target may
 * be smaller than 44 × 44 px (Tests\Support\NarrowScreen::inspect).
 *
 * Skipped locally only when Gotenberg does not answer (PDF_CHROME_URL); on
 * CI an unreachable Gotenberg fails the test (orchestrator decision 6).
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    portalWorld();
});

afterEach(fn () => billsFreshRequest());

/**
 * Lays each page out at 360 px; the pages that scroll sideways or have
 * small targets, with what was measured.
 *
 * @param  array<string, TestResponse>  $pages
 * @return array<string, array{viewport: int, overflow: int, targets: int, small: int}>
 */
function portalPagesFailingOnPhones(string $gotenberg, array $pages): array
{
    $failing = [];

    foreach ($pages as $name => $page) {
        // A link that no longer works is the one page answered 404 (P03-01b).
        expect($page->status())->toBe(str_contains($name, 'no longer works') ? 404 : 200, $name);
        $screen = NarrowScreen::inspect($gotenberg, NarrowScreen::standalone((string) $page->getContent()), 360);

        if ($screen['viewport'] !== 360 || $screen['overflow'] !== 0 || $screen['small'] !== 0) {
            $failing[$name] = $screen;
        }
    }

    return $failing;
}

describe('acceptance: portal usable at 360 px width', function () {
    it('lays out every portal page of a tenant with bills, proofs and receipts on a 360 px screen: no sideways scrolling, 44 px targets', function () {
        $gotenberg = requireGotenberg();

        // A proof waiting, a rejected one, a receipt and a voided receipt.
        $waiting = submitTenantProof($this->acme, $this->kemi, $this->bill, proofFile('transfer.png', 1), ['amount' => '500,000']);
        $rejected = submitTenantProof($this->acme, $this->kemi, $this->bill, proofFile('again.png', 2), ['amount' => '200,000']);
        inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, storedPayment($this->acme, $rejected->paymentId), 'No such credit on our statement for 24/12/2026'));
        $receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '300,000'));
        $voided = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '100,000'));
        voidReceiptAlone($this->acme, $this->owner, $voided);
        expect($waiting->proofId)->toBeInt();

        $failing = portalPagesFailingOnPhones($gotenberg, [
            'home' => portalPage($this->kemi),
            'my bills' => portalPage($this->kemi, '/bills'),
            'bill' => portalPage($this->kemi, "/bills/{$this->bill->id}"),
            'send a proof' => portalPage($this->kemi, "/bills/{$this->bill->id}/proof"),
            'invoice (web)' => portalPage($this->kemi, "/bills/{$this->bill->id}/invoice"),
            'lease' => portalPage($this->kemi, "/leases/{$this->lease->id}"),
            'my payments' => portalPage($this->kemi, '/payments'),
            'receipt' => portalPage($this->kemi, "/receipts/{$receipt->id}"),
            'voided receipt' => portalPage($this->kemi, "/receipts/{$voided->id}"),
            'receipt (web)' => portalPage($this->kemi, "/receipts/{$receipt->id}/document"),
            'my documents' => portalPage($this->kemi, '/documents'),
        ]);

        expect($failing)->toBe([]);
    });

    it('lays out the empty, read-only and paid states on a 360 px screen too', function () {
        $gotenberg = requireGotenberg();
        $nobody = User::factory()->create(['name' => 'Ngozi Eze', 'email' => 'ngozi.eze@example.com']);
        $paid = $this->neighbourBill;
        confirmedPayment($this->acme, $this->owner, inOrganization($this->acme, fn () => TenantProfile::query()->where('user_id', $this->tunde->id)->sole()), '1,800,000');
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $failing = portalPagesFailingOnPhones($gotenberg, [
            'home (nothing rented)' => portalPage($nobody),
            'my bills (none)' => portalPage($nobody, '/bills'),
            'my payments (none)' => portalPage($nobody, '/payments'),
            'my documents (none)' => portalPage($nobody, '/documents'),
            'home (agency suspended)' => portalPage($this->kemi),
            'bill (agency suspended)' => portalPage($this->kemi, "/bills/{$this->bill->id}"),
            'send a proof (agency suspended)' => portalPage($this->kemi, "/bills/{$this->bill->id}/proof"),
            'bill (paid)' => portalPage($this->tunde, "/bills/{$paid->id}"),
            'send a proof (paid bill)' => portalPage($this->tunde, "/bills/{$paid->id}/proof"),
        ]);

        expect($failing)->toBe([]);
    });

    it('lays out the portal\'s invitation and sign-in link pages on a 360 px screen too', function () {
        $gotenberg = requireGotenberg();
        $invited = tenantIn($this->acme, ['first_name' => 'Ngozi', 'last_name' => 'Eze', 'phone' => '+2348036667777', 'email' => 'ngozi.eze@example.com']);
        $invitation = inviteByLink($this->owner, $invited);
        $phoneOnly = tenantIn($this->acme, ['first_name' => 'Musa', 'last_name' => 'Bello', 'email' => null, 'phone' => '+2348037778888']);
        portalAccountFor($this->acme, $phoneOnly);
        $signIn = signInLinkByLink($this->owner, freshTenant($this->acme, $phoneOnly));

        $failing = portalPagesFailingOnPhones($gotenberg, [
            'invitation' => openPortalInvitation($invitation),
            'sign-in link' => asBrowser()->get("/portal/sign-in/{$signIn}"),
            'link that no longer works' => asBrowser()->get('/portal/sign-in/'.str_repeat('b', 40)),
            'welcome' => portalPage($this->kemi, '/welcome'),
        ]);

        expect($failing)->toBe([]);
    });

    it('catches a page that scrolls sideways or has small targets (the check itself)', function () {
        $gotenberg = requireGotenberg();
        $page = fn (string $body): string => '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0">'.$body.'</body></html>';

        $good = NarrowScreen::inspect($gotenberg, $page('<a href="/a" style="display:block;min-height:44px">A big link</a><p>Text with <a href="/b">a link in a sentence</a>.</p><a href="#main" style="position:absolute;top:-3rem">Skip</a>'));
        $small = NarrowScreen::inspect($gotenberg, $page('<button style="width:40px;height:40px">x</button><a href="/c" style="display:inline-block;height:20px">Tiny</a>'));
        $wide = NarrowScreen::inspect($gotenberg, $page('<div style="width:420px">A table too wide for a phone</div>'));

        expect($good)->toBe(['viewport' => 360, 'overflow' => 0, 'targets' => 2, 'small' => 0])
            ->and($small)->toMatchArray(['targets' => 2, 'small' => 2, 'overflow' => 0])
            ->and($wide['overflow'])->toBe(60);
    });
});
