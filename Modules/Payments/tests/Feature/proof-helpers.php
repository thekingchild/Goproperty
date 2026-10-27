<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Modules\Billing\Models\Bill;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\SubmittedProof;
use Modules\Payments\Livewire\ProofForm;
use Modules\Payments\Models\Payment;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Proofs\ProofSubmissions;
use Modules\Tenants\Models\TenantProfile;

/*
 * Helpers for the proof-of-payment tests (P03-09): proofs through the real
 * writer (Proofs\ProofSubmissions, as the portal's Contracts\PaymentProofs
 * and the agency's upload page call it), files made with GD (the
 * validator decodes images, so fakes without pixels will not do).
 */

require_once __DIR__.'/receipt-helpers.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';
require_once __DIR__.'/../../../../tests/Support/media.php';

/**
 * A real PNG "screenshot", the same bytes for the same seed: the same
 * screenshot sent twice has the same SHA-256.
 */
function proofImageBytes(int $seed = 1, int $width = 60, int $height = 40): string
{
    $image = imagecreatetruecolor($width, $height);
    $colour = (int) imagecolorallocate($image, ($seed * 37) % 256, ($seed * 71) % 256, ($seed * 113) % 256);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $colour);
    imagestring($image, 2, 2, 2, 'TRF '.$seed, (int) imagecolorallocate($image, 255, 255, 255));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** The screenshot of $seed as an upload, under any name. */
function proofFile(string $name = 'transfer.png', int $seed = 1): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, proofImageBytes($seed));
}

/** What the tenant types with a proof, with changes. */
function proofInput(array $changes = []): array
{
    return $changes + [
        'amount' => '100,000',
        'paidOn' => now('Africa/Lagos')->toDateString(),
        'senderName' => 'Kemi Adebayo',
        'bank' => 'Guaranty Trust Bank (GTBank)',
        'reference' => 'Rent for January',
        'note' => '',
    ];
}

/** Gives the tenant record a portal account (as accepting a P03-01b invitation does) and returns it. */
function proofPortalAccount(Organization $organization, TenantProfile $tenant, ?User $user = null): User
{
    $user ??= User::factory()->create(['name' => $tenant->first_name.' '.$tenant->last_name]);
    inOrganization($organization, fn () => TenantProfile::query()->findOrFail($tenant->id)->forceFill(['user_id' => $user->id])->save());

    return $user;
}

/** The tenant submits a proof for the bill from the portal (Contracts\PaymentProofs), inside the organization. */
function submitTenantProof(Organization $organization, User $tenant, Bill|int $bill, ?UploadedFile $file = null, array $changes = []): SubmittedProof
{
    return inOrganization($organization, fn (): SubmittedProof => app(PaymentProofs::class)->submitForBill($tenant, $bill instanceof Bill ? $bill->id : $bill, proofInput($changes), $file ?? proofFile()));
}

/** A member uploads a proof the tenant sent them. */
function submitMemberProof(Organization $organization, User $member, TenantProfile|int $tenant, ?Bill $bill = null, ?UploadedFile $file = null, array $changes = []): PaymentProof
{
    return inOrganization($organization, fn (): PaymentProof => app(ProofSubmissions::class)->submitForMember($member, proofInput($changes) + [
        'tenantId' => (string) ($tenant instanceof TenantProfile ? $tenant->id : $tenant),
        'billId' => $bill !== null ? (string) $bill->id : '',
    ], $file ?? proofFile()));
}

/** The proof as stored now. */
function storedProof(Organization $organization, PaymentProof|SubmittedProof|int $proof): PaymentProof
{
    $id = match (true) {
        $proof instanceof PaymentProof => $proof->id,
        $proof instanceof SubmittedProof => $proof->proofId,
        default => $proof,
    };

    return inOrganization($organization, fn (): PaymentProof => PaymentProof::query()->findOrFail($id));
}

/** Every proof of the organization, oldest first. */
function proofsOf(Organization $organization): Collection
{
    return inOrganization($organization, fn () => PaymentProof::query()->orderBy('id')->get());
}

/** The proof's payment as stored now. */
function proofPayment(Organization $organization, PaymentProof|SubmittedProof $proof): Payment
{
    return storedPayment($organization, $proof instanceof SubmittedProof ? $proof->paymentId : $proof->payment_id);
}

/** The proof upload page as a Livewire test, inside the organization (uploads need Livewire's test client). */
function proofFormPage(User $user, Organization $organization, array $query = []): mixed
{
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::withQueryParams($query)->test(ProofForm::class);
}

/**
 * Chooses the file on a Livewire page. Livewire's test uploader clears
 * temporary uploads it thinks are a day old, judged by the (travelled)
 * clock, so the upload itself happens at the real time.
 */
function chooseProofFile(mixed $page, UploadedFile $file): mixed
{
    $now = now();
    test()->travelBack();
    $page->set('file', $file);
    test()->travelTo($now);

    return $page;
}
