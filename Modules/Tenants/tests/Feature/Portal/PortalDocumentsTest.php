<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Documents\Verification\VerificationCodes;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\MediaUrls;
use App\Platform\Impersonation\ImpersonationMode;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Tenants\Models\TenantProfile;

require_once __DIR__.'/portal-world.php';
require_once __DIR__.'/../../../../Payments/tests/Feature/proof-helpers.php';

/*
 * P03-11b: receipts and documents in the tenant portal (BRANDING_DOCUMENTS.md
 * § 5, ARCHITECTURE.md § 9, PLATFORM_CONSOLE.md § 6). My payments lists the
 * receipts and proofs, a receipt's page has the verify link, My documents
 * lists the lease contract, invoices and receipts with their VOID copies;
 * every file is the kept evidence file, opened only through a short-lived
 * signed link after TenantProfilePolicy::downloadAsTenant. Another tenant's
 * or organization's proof, receipt, invoice or contract answers 404, like a
 * missing one; GoProperty staff impersonating the tenant open none of them.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    portalWorld();

    // Kemi: a proof waiting, a receipt, and a receipt voided by the agency.
    $this->proof = submitTenantProof($this->acme, $this->kemi, $this->bill, proofFile('kemi-transfer.png', 11), ['amount' => '500,000']);
    $this->receipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '300,000'));
    $this->voided = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $this->kemiAtAcme, '100,000'));
    voidReceiptAlone($this->acme, $this->owner, $this->voided, 'Recorded twice for one transfer');

    // Tunde (her neighbour) and Bello's tenant have the same kinds of records.
    $tunde = inOrganization($this->acme, fn (): TenantProfile => TenantProfile::query()->where('user_id', $this->tunde->id)->sole());
    $this->neighbourProof = submitMemberProof($this->acme, $this->owner, $tunde, $this->neighbourBill, proofFile('tunde.png', 12));
    $this->neighbourReceipt = receiptOf($this->acme, confirmedPayment($this->acme, $this->owner, $tunde, '200,000'));
    voidReceiptAlone($this->acme, $this->owner, $this->neighbourReceipt, 'Wrong tenant');
    $foreign = inOrganization($this->other, fn (): TenantProfile => TenantProfile::query()->where('first_name', 'Foreign')->sole());
    $this->foreignProof = submitMemberProof($this->other, $this->otherOwner, $foreign, $this->foreignBill, proofFile('foreign.png', 13));
    $this->foreignReceipt = receiptOf($this->other, confirmedPayment($this->other, $this->otherOwner, $foreign, '400,000'));
    voidReceiptAlone($this->other, $this->otherOwner, $this->foreignReceipt, 'Wrong tenant');
});

afterEach(fn () => billsFreshRequest());

/** Kemi's own files: path => what the file starts with. */
function kemisFiles(): array
{
    return [
        'contract' => ['/leases/'.test()->lease->id.'/contract', '%PDF'],
        'invoice' => ['/bills/'.test()->bill->id.'/invoice.pdf', '%PDF'],
        'receipt' => ['/receipts/'.test()->receipt->id.'/pdf', '%PDF'],
        'VOID copy' => ['/receipts/'.test()->voided->id.'/void.pdf', '%PDF'],
        'proof' => ['/proofs/'.test()->proof->proofId.'/file', "\x89PNG"],
    ];
}

/** Follows a signed media link as a browser that is not signed in. */
function openMediaLink(string $url): TestResponse
{
    billsFreshRequest();
    test()->flushSession();
    app('auth')->forgetGuards();

    return test()->get($url);
}

describe('acceptance: documents are served only through signed, expiring links', function () {
    it('sends each of the tenant\'s files as a short-lived signed link to the kept file, and nothing once it expires or is changed', function (string $which) {
        [$path, $starts] = kemisFiles()[$which];

        $redirect = portalPage($this->kemi, $path)->assertRedirect();
        $url = (string) $redirect->headers->get('Location');
        expect($redirect->headers->get('Cache-Control'))->toContain('no-store')
            ->and($url)->toStartWith(url('/media/'.$this->acme->id.'/'))
            ->and($url)->toContain('expires=')
            ->and($url)->toContain('signature=');

        // The link serves the file, as kept (the same file every time).
        $file = openMediaLink($url)->assertOk();
        expect($file->streamedContent())->toStartWith($starts);
        $again = (string) portalPage($this->kemi, $path)->headers->get('Location');
        expect(Str::between($again, '/media/', '?'))->toBe(Str::between($url, '/media/', '?'));

        // Changed, or for another organization: nothing.
        openMediaLink(str_replace('signature=', 'signature=0', $url))->assertNotFound();
        openMediaLink(str_replace('/media/'.$this->acme->id.'/', '/media/'.$this->other->id.'/', $url))->assertNotFound();

        // Expired: nothing.
        $this->travel(MediaUrls::lifetimeMinutes() + 1)->minutes();
        openMediaLink($url)->assertNotFound();
    })->with(['contract', 'invoice', 'receipt', 'VOID copy', 'proof']);

    it('renders each PDF once and keeps it: the contract, invoice and receipt are the evidence files members open too', function () {
        $first = (string) portalPage($this->kemi, '/receipts/'.$this->receipt->id.'/pdf')->headers->get('Location');
        $kept = storedReceipt($this->acme, $this->receipt)->document_media_id;

        expect($kept)->not->toBeNull();

        // A member's download serves the same file.
        billsFreshRequest();
        $member = (string) $this->actingAs($this->owner)->get('/o/acme-homes/receipts/'.$this->receipt->id.'/pdf')->headers->get('Location');
        expect(Str::between($member, '/media/', '?'))->toBe(Str::between($first, '/media/', '?'));
    });

    it('opens the receipt as a web page, never cached', function () {
        portalPage($this->kemi, '/receipts/'.$this->receipt->id.'/document')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($this->receipt->number)
            ->assertSee('Kemi Adebayo');
        portalPage($this->kemi, '/receipts/'.$this->voided->id.'/document')->assertOk()->assertSee('VOID')->assertSee('Recorded twice for one transfer');
    });
});

describe('sub: a tenant never sees or downloads another tenant\'s or organization\'s records', function () {
    it('answers 404 for another tenant\'s or organization\'s proof, receipt, invoice or contract, like a missing one', function () {
        $ids = [
            '{neighbourProof}' => $this->neighbourProof->id,
            '{foreignProof}' => $this->foreignProof->id,
            '{neighbourReceipt}' => $this->neighbourReceipt->id,
            '{foreignReceipt}' => $this->foreignReceipt->id,
            '{neighbourBill}' => $this->neighbourBill->id,
            '{foreignBill}' => $this->foreignBill->id,
            '{neighbourLease}' => $this->neighbourLease->id,
            '{foreignLease}' => $this->foreignLease->id,
            '{missing}' => 999999,
        ];
        $paths = [
            'neighbour\'s proof file' => '/proofs/{neighbourProof}/file',
            'other organization\'s proof file' => '/proofs/{foreignProof}/file',
            'neighbour\'s receipt' => '/receipts/{neighbourReceipt}',
            'neighbour\'s receipt (web)' => '/receipts/{neighbourReceipt}/document',
            'neighbour\'s receipt PDF' => '/receipts/{neighbourReceipt}/pdf',
            'neighbour\'s VOID copy' => '/receipts/{neighbourReceipt}/void.pdf',
            'other organization\'s receipt' => '/receipts/{foreignReceipt}',
            'other organization\'s receipt (web)' => '/receipts/{foreignReceipt}/document',
            'other organization\'s receipt PDF' => '/receipts/{foreignReceipt}/pdf',
            'other organization\'s VOID copy' => '/receipts/{foreignReceipt}/void.pdf',
            'neighbour\'s invoice PDF' => '/bills/{neighbourBill}/invoice.pdf',
            'other organization\'s invoice PDF' => '/bills/{foreignBill}/invoice.pdf',
            'neighbour\'s contract' => '/leases/{neighbourLease}/contract',
            'other organization\'s contract' => '/leases/{foreignLease}/contract',
            'missing proof' => '/proofs/{missing}/file',
            'missing receipt' => '/receipts/{missing}',
            'missing receipt PDF' => '/receipts/{missing}/pdf',
            'missing invoice PDF' => '/bills/{missing}/invoice.pdf',
            'missing contract' => '/leases/{missing}/contract',
        ];

        foreach ($paths as $case => $path) {
            $response = portalPage($this->kemi, strtr($path, array_map(strval(...), $ids)));

            expect($response->status())->toBe(404, $case)
                ->and($response->headers->get('Location'))->toBeNull()
                ->and((string) $response->getContent())->not->toContain('Tunde')->not->toContain('Foreign');
        }
    });

    it('answers 404 for the VOID copy of a receipt that is not voided, and for a draft lease\'s contract', function () {
        portalPage($this->kemi, '/receipts/'.$this->receipt->id.'/void.pdf')->assertNotFound();

        $draft = draftLease($this->acme, $this->owner, unitIn($this->acme, buildingIn($this->acme, ['name' => 'Draft House', 'slug' => 'draft-house']), ['unit_label' => 'Room 1']), $this->kemiAtAcme);
        portalPage($this->kemi, "/leases/{$draft->id}/contract")->assertNotFound();
        portalPage($this->kemi, '/documents')->assertOk()->assertDontSee(route('portal.leases.contract', $draft->id), false);
    });

    it('lists only the tenant\'s own proofs, receipts and documents', function () {
        $payments = portalPage($this->kemi, '/payments')->assertOk()->assertDontSee('Bello Homes');
        expect(portalIds($payments, 'receipt'))->toEqualCanonicalizing([$this->receipt->id, $this->voided->id])
            ->and(portalIds($payments, 'proof'))->toBe([$this->proof->proofId]);

        $documents = (string) portalPage($this->kemi, '/documents')->assertOk()->getContent();
        foreach ([
            route('portal.leases.contract', $this->lease->id),
            route('portal.bills.invoice-pdf', $this->bill->id),
            route('portal.receipts.pdf', $this->receipt->id),
            route('portal.receipts.pdf', $this->voided->id),
            route('portal.receipts.void-pdf', $this->voided->id),
        ] as $own) {
            expect($documents)->toContain('href="'.$own.'"');
        }
        foreach ([
            route('portal.leases.contract', $this->neighbourLease->id),
            route('portal.leases.contract', $this->foreignLease->id),
            route('portal.bills.invoice-pdf', $this->neighbourBill->id),
            route('portal.bills.invoice-pdf', $this->foreignBill->id),
            route('portal.receipts.pdf', $this->neighbourReceipt->id),
            route('portal.receipts.pdf', $this->foreignReceipt->id),
        ] as $theirs) {
            expect($documents)->not->toContain('href="'.$theirs.'"');
        }

        // The neighbour sees his own, never hers.
        $his = portalPage($this->tunde, '/payments')->assertOk();
        expect(portalIds($his, 'receipt'))->toBe([$this->neighbourReceipt->id])
            ->and(portalIds($his, 'proof'))->toBe([$this->neighbourProof->id]);
    });
});

describe('receipts in the portal', function () {
    it('shows a receipt with who confirmed it, what it paid and the verify link anyone can check', function () {
        $code = storedReceipt($this->acme, $this->receipt)->verification_code;

        portalPage($this->kemi, '/receipts/'.$this->receipt->id)->assertOk()
            ->assertSee('Receipt '.$this->receipt->number)
            ->assertSee('Adeyemi Realty')
            ->assertSee('₦300,000')
            ->assertSee('Confirmed by')
            ->assertSee('Ada Owner')
            ->assertSee($this->bill->number)
            ->assertSee(route('portal.bills.show', $this->bill->id), false)
            ->assertSee(VerificationCodes::url($code), false)
            ->assertSee(route('portal.receipts.document', $this->receipt->id), false)
            ->assertSee(route('portal.receipts.pdf', $this->receipt->id), false)
            ->assertDontSee(route('portal.receipts.void-pdf', $this->receipt->id), false)
            ->assertDontSee('This receipt was voided');

        billsFreshRequest();
        $this->get(VerificationCodes::url($code))->assertOk()->assertSee($this->receipt->number);

        portalPage($this->kemi, '/receipts/'.$this->voided->id)->assertOk()
            ->assertSee('This receipt was voided')
            ->assertSee('Recorded twice for one transfer')
            ->assertSee(route('portal.receipts.void-pdf', $this->voided->id), false);
    });

    it('keeps receipts and documents open while the agency is suspended', function () {
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        portalPage($this->kemi, '/payments')->assertOk()->assertSee('Read-only for now')->assertSee($this->receipt->number);
        portalPage($this->kemi, '/receipts/'.$this->receipt->id)->assertOk()->assertSee(route('portal.receipts.pdf', $this->receipt->id), false);
        portalPage($this->kemi, '/documents')->assertOk()->assertSee(route('portal.leases.contract', $this->lease->id), false);

        foreach (kemisFiles() as [$path]) {
            portalPage($this->kemi, $path)->assertRedirect();
        }
    });
});

describe('sub: impersonating staff', function () {
    beforeEach(function () {
        // Kemi also works for Acme as an agent: staff impersonate her in Acme.
        joinAs($this->kemi, $this->acme, OrganizationRole::Agent);
    });

    it('blocks every document download while GoProperty staff impersonate the tenant, as elsewhere, in either mode', function (ImpersonationMode $mode) {
        $handOff = startSupport(staffMember(), $this->kemi, $this->acme, $mode);
        enterSupport($handOff)->assertRedirect();

        foreach ([...array_column(kemisFiles(), 0), '/receipts/'.$this->receipt->id.'/document'] as $path) {
            asSupport()->get('/portal'.$path)->assertForbidden();
        }

        // The pages still show what there is, with nothing to open.
        asSupport()->get('/portal/receipts/'.$this->receipt->id)->assertOk()
            ->assertSee($this->receipt->number)
            ->assertSee('does not open in a GoProperty support session')
            ->assertDontSee(route('portal.receipts.pdf', $this->receipt->id), false);
        asSupport()->get('/portal/documents')->assertOk()
            ->assertSee('do not open in a GoProperty support session')
            ->assertSee('Agreement '.storedLease($this->acme, $this->lease)->contract_number)
            ->assertDontSee(route('portal.leases.contract', $this->lease->id), false)
            ->assertDontSee(route('portal.bills.invoice-pdf', $this->bill->id), false);
        asSupport()->get('/portal/payments')->assertOk()
            ->assertSee($this->receipt->number)
            ->assertDontSee(route('portal.proofs.file', $this->proof->proofId), false);
        asSupport()->get("/portal/bills/{$this->bill->id}")->assertOk()->assertDontSee(route('portal.bills.invoice-pdf', $this->bill->id), false);

        if ($mode === ImpersonationMode::Write) {
            expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
        }

        // Signed in herself, she opens them.
        billsFreshRequest();
        $this->flushSession();
        portalPage($this->kemi, '/receipts/'.$this->receipt->id.'/pdf')->assertRedirect();
    })->with([
        'read-only' => [ImpersonationMode::ReadOnly],
        'write' => [ImpersonationMode::Write],
    ]);
});

it('sends guests to sign in', function () {
    foreach (['/payments', '/documents', '/receipts/1', '/receipts/1/pdf', '/receipts/1/void.pdf', '/receipts/1/document', '/bills/1/invoice.pdf', '/bills/1/proof', '/leases/1/contract', '/proofs/1/file'] as $path) {
        billsFreshRequest();
        $this->flushSession();
        app('auth')->forgetGuards();
        $this->get('/portal'.$path)->assertRedirect('/login');
    }
});
