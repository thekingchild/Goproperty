<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Media\EvidenceFileProtected;
use App\Media\Media;
use App\Models\Organization;
use App\Models\User;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\CurrentImpersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Bills\BillService;
use Modules\Billing\Enums\BillStatus;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Contracts\PaymentProofs;
use Modules\Payments\Contracts\PaymentSource;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Enums\ProofStatus;
use Modules\Payments\Enums\ProofSubmitter;
use Modules\Payments\Models\PaymentNotice;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Payments\PaymentService;
use Modules\Payments\Proofs\ProofSubmissions;
use Modules\Tenants\Models\TenantProfile;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/proof-helpers.php';

/*
 * P03-09: proofs of payment (ONBOARDING_COMPLIANCE.md § 4.1): a tenant
 * submits one from the portal (Contracts\PaymentProofs, for P03-11b) for
 * their own bill, or a member uploads what the tenant sent them; either
 * records a bank transfer waiting for confirmation (source `proof_upload`)
 * with the file in the evidence collection `payment_proofs`. The same file
 * uploaded twice in an organization is flagged for the reviewer (never
 * refused for it); files are checked by App\Media\UploadValidator; proofs
 * are evidence (archived, never deleted); uploads are rate-limited per
 * bill and per tenant. Every row that must not match a search has fixed
 * names, phones and emails.
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    holdInvoicePdfs();
    holdReceiptPdfs();
    seedOrganizationRoles();
    $this->travelTo('2026-12-25 09:00:00');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['slug' => 'other-realty', 'name' => 'Bello Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Tunde Bakare']), $this->acme);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Bisi Bello']), $this->other);
    $this->palm = buildingIn($this->acme, ['name' => 'Palm Court', 'slug' => 'palm-court']);
    $this->kemi = tenantIn($this->acme, ['first_name' => 'Kemi', 'last_name' => 'Adebayo', 'phone' => '+2348030000001', 'email' => 'kemi@example.test']);
    $this->tola = tenantIn($this->acme, ['first_name' => 'Tola', 'last_name' => 'Ade', 'phone' => '+2348030000002', 'email' => 'tola@example.test']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 1']), $this->kemi, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 2']), $this->tola, ['billingCycle' => 'monthly', 'rent' => '80,000', 'deposit' => '0']);
    $this->stranger = tenantIn($this->other, ['first_name' => 'Foreign', 'last_name' => 'Tenant', 'phone' => '+2348030000009', 'email' => 'foreign@example.test']);
    activeLease($this->other, $this->otherOwner, unitIn($this->other, buildingIn($this->other, ['name' => 'Foreign Court'])), $this->stranger, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
    runBillGeneration();

    $this->bill = billsOfTenant($this->acme, $this->kemi)->sole();
    $this->tolasBill = billsOfTenant($this->acme, $this->tola)->sole();
    $this->foreignBill = billsOfTenant($this->other, $this->stranger)->sole();
    // Kemi uses the tenant portal (P03-01b): her record is linked to her account; she has no workspace role.
    $this->kemiUser = proofPortalAccount($this->acme, $this->kemi, User::factory()->create(['name' => 'Kemi Adebayo', 'email' => 'kemi@example.test']));
});

afterEach(fn () => billsFreshRequest());

/** @return array<string, bool> ability => allowed, as PaymentProofPolicy answers inside the organization */
function proofAbilitiesOf(User $user, Organization $organization, PaymentProof $proof): array
{
    return inOrganization($organization, fn (): array => [
        'viewAny' => Gate::forUser($user)->allows('viewAny', PaymentProof::class),
        'view' => Gate::forUser($user)->allows('view', $proof),
        'viewFile' => Gate::forUser($user)->allows('viewFile', $proof),
        'create' => Gate::forUser($user)->allows('create', PaymentProof::class),
        'archive' => Gate::forUser($user)->allows('archive', $proof),
        'update' => Gate::forUser($user)->allows('update', $proof),
        'delete' => Gate::forUser($user)->allows('delete', $proof),
        'forceDelete' => Gate::forUser($user)->allows('forceDelete', $proof),
    ]);
}

/** The proof file's path on the media disk. */
function proofFilePath(Organization $organization, PaymentProof $proof): string
{
    return inOrganization($organization, fn (): string => Media::query()->findOrFail($proof->media_id)->getPathRelativeToRoot());
}

describe('acceptance: the same image uploaded twice is flagged', function () {
    it('flags the second upload of the same file for the reviewer, refuses neither, and leaves other files alone', function () {
        $first = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG_2041.png', 7));
        // The same screenshot again, under another name, a week later.
        $this->travel(7)->days();
        $second = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('Screenshot copy.png', 7), ['amount' => '50,000']);
        $other = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG_2042.png', 8), ['amount' => '20,000']);
        // A member uploads the same file again, for another tenant: flagged too.
        $forTola = submitMemberProof($this->acme, $this->owner, $this->tola, $this->tolasBill, proofFile('from-whatsapp.png', 7), ['amount' => '80,000', 'senderName' => 'Tola Ade']);
        // The same file in another organization is that organization's first.
        $foreign = submitMemberProof($this->other, $this->otherOwner, $this->stranger, $this->foreignBill, proofFile('IMG_2041.png', 7));

        $hash = hash('sha256', proofImageBytes(7));
        expect($first->isDuplicate())->toBeFalse()
            ->and($second->isDuplicate())->toBeTrue()
            ->and($second->duplicateOfProofId)->toBe($first->proofId)
            ->and($other->isDuplicate())->toBeFalse()
            ->and($forTola->duplicate_of_proof_id)->toBe($first->proofId)
            ->and($foreign->duplicate_of_proof_id)->toBeNull()
            ->and(storedProof($this->acme, $first)->file_sha256)->toBe($hash)
            ->and(storedProof($this->acme, $first)->first_upload_sha256)->toBe($hash)
            ->and(storedProof($this->acme, $second)->file_sha256)->toBe($hash)
            ->and(storedProof($this->acme, $second)->first_upload_sha256)->toBeNull()
            ->and(storedProof($this->other, $foreign)->first_upload_sha256)->toBe($hash);

        // Flagged, never refused: every payment waits for the reviewer.
        foreach ([$first, $second, $other] as $proof) {
            expect(proofPayment($this->acme, $proof)->status)->toBe(PaymentStatus::PendingConfirmation);
        }

        // The audit trail says so.
        $audit = AuditEntry::query()->where('event', 'payment_proof.submitted')->where('subject_id', $second->proofId)->sole();
        expect($audit->properties['duplicate_of_proof_id'])->toBe($first->proofId)
            ->and($audit->properties['file_sha256'])->toBe($hash)
            ->and($audit->description)->toContain('same file');

        // The reviewer sees it in the queue and on both payments' pages, with links to the other uploads.
        $queue = paymentsPage($this->owner, '?status=pending_confirmation')->assertOk();
        $queue->assertSee('data-test="duplicate-'.$first->paymentId.'"', false)
            ->assertSee('data-test="duplicate-'.$second->paymentId.'"', false)
            ->assertSee('data-test="duplicate-'.$forTola->payment_id.'"', false)
            ->assertDontSee('data-test="duplicate-'.$other->paymentId.'"', false)
            ->assertSee('data-test="proof-'.$other->paymentId.'"', false);

        $page = paymentsPage($this->owner, '/'.$second->paymentId)->assertOk()
            ->assertSee('data-test="proof-duplicate"', false)
            ->assertSee('/o/acme-homes/payments/'.$first->paymentId, false)
            ->assertSee('/o/acme-homes/payments/'.$forTola->payment_id, false)
            ->assertDontSee('/o/acme-homes/payments/'.$other->paymentId, false);
        expect(substr_count(htmlOf($page), 'data-test="same-file-proof"'))->toBe(2);
        paymentsPage($this->owner, '/'.$first->paymentId)->assertOk()->assertSee('data-test="proof-duplicate"', false)->assertSee('/o/acme-homes/payments/'.$second->paymentId, false);
        paymentsPage($this->owner, '/'.$other->paymentId)->assertOk()->assertDontSee('data-test="proof-duplicate"', false);
        // Another organization's upload of the same file is never shown.
        paymentsPage($this->otherOwner, '/'.$foreign->payment_id, 'other-realty')->assertOk()->assertDontSee('data-test="proof-duplicate"', false);

        // The reviewer decides: the copy is rejected with a reason, the first confirmed.
        inOrganization($this->acme, fn () => app(PaymentService::class)->reject($this->owner, proofPayment($this->acme, $second), 'Same screenshot as the 25 December transfer'));
        confirmPayment($this->acme, $this->owner, proofPayment($this->acme, $first));
        expect(inOrganization($this->acme, fn () => storedProof($this->acme, $second)->status()))->toBe(ProofStatus::Rejected)
            ->and(inOrganization($this->acme, fn () => storedProof($this->acme, $first)->status()))->toBe(ProofStatus::Accepted)
            ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid);
    });

    it('holds one "first upload" per file and organization, so two racing uploads cannot both count as the first', function () {
        $first = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG_1.png', 3));
        $stored = storedProof($this->acme, $first);

        // What a second upload racing the first would try to write: the unique key refuses it.
        expect(fn () => inOrganization($this->acme, fn () => tap(new PaymentProof, fn (PaymentProof $copy): bool => $copy->forceFill([
            'payment_id' => recordPayment($this->acme, $this->owner, $this->kemi, '1,000')->id,
            'tenant_profile_id' => $this->kemi->id,
            'amount_kobo' => $stored->amount_kobo,
            'paid_on' => '2026-12-25',
            'sender_name' => 'Kemi Adebayo',
            'bank_name' => 'GTBank',
            'file_sha256' => $stored->file_sha256,
            'first_upload_sha256' => $stored->file_sha256,
            'submitted_by' => ProofSubmitter::Member,
        ])->save())))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('proof files are validated', function () {
    it('refuses a file that is not a JPEG, PNG, WebP or PDF, whatever its name says, or larger than 10 MB, and records nothing', function (Closure $file, string $message) {
        $upload = $file();

        foreach ([
            fn () => submitTenantProof($this->acme, $this->kemiUser, $this->bill, $upload),
            fn () => submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, $file()),
        ] as $submit) {
            try {
                $submit();
                $this->fail('The file was accepted.');
            } catch (ValidationException $refused) {
                expect($refused->errors())->toHaveKey('file')
                    ->and($refused->errors()['file'][0])->toContain($message);
            }
        }

        expect(paymentsOf($this->acme))->toHaveCount(0)
            ->and(proofsOf($this->acme))->toHaveCount(0)
            ->and(mediaCount($this->acme))->toBe(0)
            ->and(Storage::disk('media')->allFiles())->toBe([]);
    })->with([
        'a PHP script renamed .png' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('transfer.png', "<?php system(\$_GET['c']); ?>"), 'whatever its name says'],
        'a PHP script claiming to be a JPEG' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('transfer.jpg', "<?php echo 'x';")->mimeType('image/jpeg'), 'whatever its name says'],
        'an SVG' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('transfer.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG files are not accepted'],
        'an HTML page renamed .pdf' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('receipt.pdf', '<html><body><script>alert(1)</script></body></html>'), 'whatever its name says'],
        'an empty file' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('transfer.png', ''), 'empty'],
        'a file over 10 MB' => [fn (): UploadedFile => UploadedFile::fake()->createWithContent('transfer.png', proofImageBytes(1).str_repeat("\0", 10 * 1024 * 1024)), 'larger than 10 MB'],
    ]);

    it('takes a JPEG, a WebP and a PDF, typed as sniffed, with the uploader\'s name only as a label', function () {
        $jpeg = submitTenantProof($this->acme, $this->kemiUser, $this->bill, mediaImage('../../etc/IMG 2041.jpg', 80, 60), ['amount' => '10,000']);
        $webp = submitTenantProof($this->acme, $this->kemiUser, $this->bill, mediaImage('transfer.webp', 80, 60, 'webp'), ['amount' => '10,000']);
        $pdf = submitTenantProof($this->acme, $this->kemiUser, $this->bill, mediaPdf('bank receipt.pdf'), ['amount' => '10,000']);

        $files = inOrganization($this->acme, fn () => collect([$jpeg, $webp, $pdf])->map(fn ($proof) => Media::query()->findOrFail(storedProof($this->acme, $proof)->media_id))->all());

        expect(array_map(fn (Media $media): string => $media->mime_type, $files))->toBe(['image/jpeg', 'image/webp', 'application/pdf'])
            ->and(array_map(fn (Media $media): string => $media->collection_name, $files))->toBe(['payment_proofs', 'payment_proofs', 'payment_proofs'])
            ->and($files[0]->name)->toBe('IMG 2041')
            ->and($files[0]->file_name)->toEndWith('.jpg')->not->toContain('IMG')
            ->and($files[2]->file_name)->toEndWith('.pdf');
    });

    it('checks the file on the upload page too, and shows why', function () {
        $page = proofFormPage($this->owner, $this->acme, ['bill' => (string) $this->bill->id])
            ->set('amount', '100,000')->set('senderName', 'Kemi Adebayo')->set('bank', 'GTBank');
        chooseProofFile($page, UploadedFile::fake()->createWithContent('transfer.png', "<?php system('id'); ?>"))
            ->call('save')
            ->assertHasErrors('file')
            ->assertNoRedirect();
        expect($page->errors()->first('file'))->toContain('whatever its name says');

        expect(paymentsOf($this->acme))->toHaveCount(0)->and(proofsOf($this->acme))->toHaveCount(0);
    });
});

describe('acceptance: a proof can never be hard-deleted (archive only)', function () {
    it('refuses to delete a proof or its file, whoever asks, and archives it with a reason, keeping the file', function () {
        $proof = submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill);
        $stored = storedProof($this->acme, $proof);
        $path = proofFilePath($this->acme, $stored);
        Storage::disk('media')->assertExists($path);

        // The record and its file refuse deletion; no policy allows it; no route deletes one.
        expect(fn () => inOrganization($this->acme, fn () => storedProof($this->acme, $proof)->delete()))->toThrow(LogicException::class, 'never deleted')
            ->and(fn () => inOrganization($this->acme, fn () => Media::query()->findOrFail($stored->media_id)->delete()))->toThrow(EvidenceFileProtected::class)
            ->and(proofAbilitiesOf($this->owner, $this->acme, $stored))->toMatchArray(['delete' => false, 'forceDelete' => false, 'update' => false]);
        $proofRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => str_contains($route->uri(), 'proofs'));
        expect($proofRoutes->flatMap(fn ($route): array => $route->methods())->unique()->sort()->values()->all())->toBe(['GET', 'HEAD']);

        // What was submitted never changes; the file is kept once.
        expect(fn () => inOrganization($this->acme, fn () => storedProof($this->acme, $proof)->forceFill(['amount_kobo' => $stored->amount_kobo->plus($stored->amount_kobo)])->save()))->toThrow(LogicException::class)
            ->and(fn () => inOrganization($this->acme, fn () => storedProof($this->acme, $proof)->forceFill(['media_id' => $stored->media_id + 1])->save()))->toThrow(LogicException::class);

        // "Remove" archives, from the payment page, with a reason.
        $page = paymentsSnapshot($this->owner, '/'.$proof->payment_id);
        $refused = paymentsUpdate($this->owner, $page, ['archiveReason' => ''], [['archiveProof']])->assertOk();
        expect(LivewireHttp::errors($refused))->toHaveKey('reason')
            ->and(storedProof($this->acme, $proof)->isArchived())->toBeFalse();
        paymentsUpdate($this->owner, $page, ['archiveReason' => 'Wrong screenshot: a photo of the tenant\'s ID card'], [['archiveProof']])->assertOk()->assertSee('The proof is archived. Its file is kept.');

        $archived = storedProof($this->acme, $proof);
        expect($archived->status())->toBe(ProofStatus::Archived)
            ->and($archived->archived_by_user_id)->toBe($this->owner->id)
            ->and($archived->archive_reason)->toBe('Wrong screenshot: a photo of the tenant\'s ID card')
            ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($stored->media_id)->exists()))->toBeTrue()
            ->and(AuditEntry::query()->where('event', 'payment_proof.archived')->where('subject_id', $proof->id)->sole()->properties['reason'])->toBe('Wrong screenshot: a photo of the tenant\'s ID card')
            ->and(proofAbilitiesOf($this->owner, $this->acme, $archived))->toMatchArray(['archive' => false, 'delete' => false, 'viewFile' => true]);
        Storage::disk('media')->assertExists($path);

        // Still shown, marked archived, with its file to whoever may see it; archived once only.
        paymentsPage($this->owner, '/'.$proof->payment_id)->assertOk()->assertSee('data-test="proof-archived"', false)->assertSee('data-test="proof-open"', false)->assertDontSee('data-test="archive-proof"', false);
        expect(fn () => inOrganization($this->acme, fn () => app(ProofSubmissions::class)->archive($this->owner, $archived, 'Again')))->toThrow(AuthorizationException::class)
            ->and(fn () => inOrganization($this->acme, fn () => storedProof($this->acme, $proof)->forceFill(['archived_at' => null])->save()))->toThrow(LogicException::class);
        // Archiving changes nothing about the payment: reject it if it should not count.
        expect(proofPayment($this->acme, $proof)->status)->toBe(PaymentStatus::PendingConfirmation);
    });
});

describe('sub: a tenant submits proofs only for their own bills', function () {
    it('records the tenant\'s proof as a bank transfer waiting for confirmation, with the file kept as evidence, and tells the tenant', function () {
        $submitted = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG_2041.png', 5), [
            'amount' => '₦100,000.00',
            'paidOn' => '2026-12-24',
            'senderName' => '  Kemi   Adebayo ',
            'bank' => 'Guaranty Trust Bank (GTBank)',
            'reference' => $this->bill->payment_reference,
            'note' => 'Paid from my salary account',
        ]);

        $payment = proofPayment($this->acme, $submitted);
        $proof = storedProof($this->acme, $submitted);
        $media = inOrganization($this->acme, fn () => Media::query()->findOrFail($proof->media_id));

        expect($payment->source)->toBe(PaymentSource::ProofUpload)
            ->and($payment->method)->toBe(PaymentMethod::BankTransfer)
            ->and($payment->status)->toBe(PaymentStatus::PendingConfirmation)
            ->and($payment->tenant_profile_id)->toBe($this->kemi->id)
            ->and($payment->bill_id)->toBe($this->bill->id)
            ->and($payment->amount_kobo->kobo)->toBe(10_000_000)
            ->and($payment->paid_at->toDateString())->toBe('2026-12-24')
            ->and($payment->payer_name)->toBe('Kemi Adebayo')
            ->and($payment->reference)->toBe($this->bill->payment_reference)
            ->and($payment->recorded_by_user_id)->toBe($this->kemiUser->id)
            ->and($proof->payment_id)->toBe($payment->id)
            ->and($proof->tenant_profile_id)->toBe($this->kemi->id)
            ->and($proof->bill_id)->toBe($this->bill->id)
            ->and($proof->amount_kobo->kobo)->toBe(10_000_000)
            ->and($proof->paid_on->toDateString())->toBe('2026-12-24')
            ->and($proof->sender_name)->toBe('Kemi Adebayo')
            ->and($proof->bank_name)->toBe('Guaranty Trust Bank (GTBank)')
            ->and($proof->note)->toBe('Paid from my salary account')
            ->and($proof->submitted_by)->toBe(ProofSubmitter::Tenant)
            ->and($proof->submitted_by_user_id)->toBe($this->kemiUser->id)
            ->and($proof->submitted_at->toDateTimeString())->toBe('2026-12-25 09:00:00')
            ->and(inOrganization($this->acme, fn () => $proof->status()))->toBe(ProofStatus::Waiting)
            ->and($media->collection_name)->toBe('payment_proofs')
            ->and($media->model_type)->toBe($proof->getMorphClass())
            ->and($media->model_id)->toBe($proof->id)
            ->and($media->checksum())->toBe(hash('sha256', proofImageBytes(5)))
            ->and($proof->file_sha256)->toBe($media->checksum())
            ->and(Storage::disk('media')->get($media->getPathRelativeToRoot()))->toBe(proofImageBytes(5))
            ->and(PaymentNotice::query()->withoutGlobalScopes()->where('payment_id', $payment->id)->pluck('event')->all())->toBe(['payment.recorded']);

        $audit = AuditEntry::query()->where('event', 'payment_proof.submitted')->sole();
        expect($audit->causer_id)->toBe($this->kemiUser->id)
            ->and($audit->properties['submitted_by'])->toBe('tenant')
            ->and($audit->properties['payment_id'])->toBe($payment->id)
            ->and(AuditEntry::query()->where('event', 'payment.recorded')->where('subject_id', $payment->id)->sole()->properties['source'])->toBe('proof_upload');

        expect(inOrganization($this->acme, fn (): bool => app(PaymentProofs::class)->maySubmit($this->kemiUser, $this->bill->id)))->toBeTrue();
    });

    it('answers 404 for another tenant\'s bill, another organization\'s bill, or a user who is not the bill\'s tenant, and records nothing', function () {
        $tolaUser = proofPortalAccount($this->acme, $this->tola, User::factory()->create(['name' => 'Tola Ade', 'email' => 'tola@example.test']));
        $strangerUser = proofPortalAccount($this->other, $this->stranger, User::factory()->create(['name' => 'Foreign Tenant', 'email' => 'foreign@example.test']));
        $nobody = User::factory()->create(['name' => 'Not A Tenant', 'email' => 'nobody@example.test']);
        $notFound = fn (AuthorizationException $refused) => expect($refused->status())->toBe(404);

        // Kemi and Tola: each only their own bill.
        expect(fn () => submitTenantProof($this->acme, $this->kemiUser, $this->tolasBill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->acme, $tolaUser, $this->bill))->toThrow($notFound)
            // Another organization's bill, by its id, from Kemi's organization or its own.
            ->and(fn () => submitTenantProof($this->acme, $this->kemiUser, $this->foreignBill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->other, $this->kemiUser, $this->foreignBill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->other, $this->kemiUser, $this->bill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->acme, $strangerUser, $this->bill))->toThrow($notFound)
            // A signed-in user who is nobody's tenant, and a member who is not the bill's tenant.
            ->and(fn () => submitTenantProof($this->acme, $nobody, $this->bill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->acme, $this->owner, $this->bill))->toThrow($notFound)
            ->and(fn () => submitTenantProof($this->acme, $this->kemiUser, 999_999))->toThrow($notFound);

        foreach ([[$this->kemiUser, $this->tolasBill->id], [$this->kemiUser, $this->foreignBill->id], [$nobody, $this->bill->id]] as [$user, $billId]) {
            expect(inOrganization($this->acme, fn (): bool => app(PaymentProofs::class)->maySubmit($user, $billId)))->toBeFalse();
        }

        expect(paymentsOf($this->acme))->toHaveCount(0)
            ->and(paymentsOf($this->other))->toHaveCount(0)
            ->and(proofsOf($this->acme))->toHaveCount(0)
            ->and(proofsOf($this->other))->toHaveCount(0);

        // Each of them for their own bill: accepted.
        submitTenantProof($this->acme, $tolaUser, $this->tolasBill, proofFile('t.png', 21), ['amount' => '80,000']);
        submitTenantProof($this->other, $strangerUser, $this->foreignBill, proofFile('s.png', 22));
        expect(proofsOf($this->acme))->toHaveCount(1)->and(proofsOf($this->other))->toHaveCount(1);
    });

    it('refuses a proof from an archived tenant, for a void bill or a bill already paid', function () {
        // A bill already paid.
        confirmedPayment($this->acme, $this->owner, $this->kemi, '100,000');
        expect(fn () => submitTenantProof($this->acme, $this->kemiUser, $this->bill))->toThrow(AuthorizationException::class, 'paid already');

        // A void bill.
        $tolaUser = proofPortalAccount($this->acme, $this->tola);
        inOrganization($this->acme, fn () => app(BillService::class)->void($this->owner, storedBill($this->acme, $this->tolasBill), 'Issued in error'));
        expect(fn () => submitTenantProof($this->acme, $tolaUser, $this->tolasBill))->toThrow(AuthorizationException::class, 'void');

        // An archived tenant (moved out) cannot submit for an open bill.
        $sade = tenantIn($this->acme, ['first_name' => 'Sade', 'last_name' => 'Okoro', 'phone' => '+2348030000005', 'email' => 'sade@example.test']);
        activeLease($this->acme, $this->owner, unitIn($this->acme, $this->palm, ['unit_label' => 'Flat 3']), $sade, ['billingCycle' => 'monthly', 'rent' => '70,000', 'deposit' => '0']);
        runBillGeneration();
        $sadesBill = billsOfTenant($this->acme, $sade)->sole();
        $sadeUser = proofPortalAccount($this->acme, $sade);
        // Archived as the tenants page does it (a soft delete), with the lease still open.
        inOrganization($this->acme, fn () => TenantProfile::query()->whereKey($sade->id)->update(['deleted_at' => now()]));
        expect(fn () => submitTenantProof($this->acme, $sadeUser, $sadesBill))->toThrow(fn (AuthorizationException $refused) => expect($refused->status())->toBe(404));

        expect(proofsOf($this->acme))->toHaveCount(0);
    });
});

describe('sub: proof uploads are rate-limited per bill and per tenant', function () {
    it('takes at most 5 proofs for one bill an hour, whoever sends them, then again an hour later', function () {
        foreach (range(1, 4) as $seed) {
            submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile("p{$seed}.png", $seed), ['amount' => '1,000']);
        }
        submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, proofFile('p5.png', 5), ['amount' => '1,000']);

        foreach ([
            fn () => submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('p6.png', 6), ['amount' => '1,000']),
            fn () => submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, proofFile('p6.png', 6), ['amount' => '1,000']),
        ] as $submit) {
            try {
                $submit();
                $this->fail('The sixth proof was accepted.');
            } catch (ValidationException $refused) {
                expect($refused->errors()['proof'][0])->toContain('Too many proofs were sent for this bill');
            }
        }

        expect(proofsOf($this->acme))->toHaveCount(5)->and(paymentsOf($this->acme))->toHaveCount(5);

        // Another tenant's bill is not held back; an hour later this one takes proofs again.
        submitMemberProof($this->acme, $this->owner, $this->tola, $this->tolasBill, proofFile('t1.png', 30), ['amount' => '1,000']);
        $this->travel(61)->minutes();
        submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('p6.png', 6), ['amount' => '1,000']);
        expect(proofsOf($this->acme))->toHaveCount(7);
    });

    it('takes at most 20 proofs for one tenant a day', function () {
        foreach (range(1, PaymentProofs::PER_TENANT_PER_DAY) as $seed) {
            submitMemberProof($this->acme, $this->owner, $this->kemi, null, proofFile("p{$seed}.png", $seed + 100), ['amount' => '1,000']);
        }

        expect(fn () => submitMemberProof($this->acme, $this->owner, $this->kemi, null, proofFile('one-more.png', 999), ['amount' => '1,000']))
            ->toThrow(ValidationException::class, 'Too many proofs were sent for this tenant today');
        expect(proofsOf($this->acme))->toHaveCount(20);

        $this->travel(1)->days();
        submitMemberProof($this->acme, $this->owner, $this->kemi, null, proofFile('one-more.png', 999), ['amount' => '1,000']);
        expect(proofsOf($this->acme))->toHaveCount(21);
    });
});

describe('proof review in the confirmation queue', function () {
    it('shows the proof on its payment, opens the file through a short-lived signed link, and confirms it into a receipt', function () {
        $submitted = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG_2041.png', 11), ['senderName' => 'Kemi Adebayo', 'bank' => 'Opay', 'reference' => 'NIP/2412/00071']);
        $proof = storedProof($this->acme, $submitted);

        expect(listedPayments(paymentsPage($this->owner, '?status=pending_confirmation')))->toBe([$submitted->paymentId]);
        paymentsPage($this->owner, '?source=proof_upload')->assertOk()->assertSee('data-test="proof-'.$submitted->paymentId.'"', false);

        paymentsPage($this->owner, '/'.$submitted->paymentId)->assertOk()
            ->assertSee('data-test="payment-proof"', false)
            ->assertSee('Opay')
            ->assertSee('NIP/2412/00071')
            ->assertSee('The tenant, in the portal')
            ->assertSee('/o/acme-homes/payments/proofs/'.$proof->id.'/thumb', false)
            ->assertSee('/o/acme-homes/payments/proofs/'.$proof->id.'/file', false)
            ->assertSee('data-test="confirm-payment"', false)
            ->assertDontSee('data-test="proof-duplicate"', false);

        // The file: a redirect to the signed /media link, made after the policy check; the link serves the bytes.
        $link = paymentsPage($this->owner, '/proofs/'.$proof->id.'/file')->assertRedirect()->headers->get('Location');
        expect($link)->toContain('/media/'.$this->acme->id.'/')->toContain('signature=')->toContain('expires=');
        billsFreshRequest();
        expect($this->get((string) $link)->assertOk()->streamedContent())->toBe(proofImageBytes(11));
        paymentsPage($this->owner, '/proofs/'.$proof->id.'/thumb')->assertRedirect();

        // Confirmed from the page: the bill is paid and the receipt says how.
        paymentsUpdate($this->owner, paymentsSnapshot($this->owner, '/'.$submitted->paymentId), ['note' => 'Seen on the GTB statement'], [['confirm']])->assertOk()->assertSee('The payment is confirmed.');
        $receipt = receiptOf($this->acme, $submitted->paymentId);
        expect(proofPayment($this->acme, $submitted)->status)->toBe(PaymentStatus::Confirmed)
            ->and(storedBill($this->acme, $this->bill)->status)->toBe(BillStatus::Paid)
            ->and(inOrganization($this->acme, fn () => storedProof($this->acme, $proof)->status()))->toBe(ProofStatus::Accepted)
            ->and($receipt->details['payment']['source'])->toBe('proof_upload');
        paymentsPage($this->owner, '/'.$submitted->paymentId)->assertOk()->assertSee('Accepted');
    });

    it('lets a member upload a proof the tenant sent them, from the upload page', function () {
        proofFormPage($this->owner, $this->acme, ['bill' => (string) $this->bill->id])
            ->assertSet('tenantId', (string) $this->kemi->id)
            ->call('save')
            ->assertHasErrors(['file']);

        $page = proofFormPage($this->owner, $this->acme, ['bill' => (string) $this->bill->id])
            ->set('amount', '100,000')->set('paidOn', '2026-12-23')->set('senderName', '')->set('bank', 'Access Bank');
        chooseProofFile($page, proofFile('whatsapp-image.png', 40))->call('save')->assertHasErrors(['senderName']);
        expect(proofsOf($this->acme))->toHaveCount(0)->and(paymentsOf($this->acme))->toHaveCount(0);

        chooseProofFile($page->set('senderName', 'Kemi Adebayo'), proofFile('whatsapp-image.png', 40))->call('save')->assertHasNoErrors();
        $proof = proofsOf($this->acme)->sole();
        $page->assertRedirect(route('workspace.payments.show', ['organization' => 'acme-homes', 'payment' => $proof->payment_id]));

        expect($proof->submitted_by)->toBe(ProofSubmitter::Member)
            ->and($proof->submitted_by_user_id)->toBe($this->owner->id)
            ->and($proof->bill_id)->toBe($this->bill->id)
            ->and($proof->bank_name)->toBe('Access Bank')
            ->and(proofPayment($this->acme, $proof)->recorded_by_user_id)->toBe($this->owner->id)
            ->and(proofPayment($this->acme, $proof)->status)->toBe(PaymentStatus::PendingConfirmation);
        paymentsPage($this->owner, '/'.$proof->payment_id)->assertOk()->assertSee('A member, for the tenant')->assertSee('Tunde Bakare');
        paymentsPage($this->owner)->assertOk()->assertSee('data-test="upload-proof"', false);
    });

    it('shows a narrowed agent only their tenants\' proofs, and takes their uploads only for those tenants', function () {
        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        $oak = buildingIn($this->acme, ['name' => 'Oak Lodge', 'slug' => 'oak-lodge']);
        $oakTenant = tenantIn($this->acme, ['first_name' => 'Oak', 'last_name' => 'Tenant', 'phone' => '+2348030000003', 'email' => 'oak@example.test']);
        activeLease($this->acme, $this->owner, unitIn($this->acme, $oak, ['unit_label' => 'Oak 1']), $oakTenant, ['billingCycle' => 'monthly', 'rent' => '100,000', 'deposit' => '0']);
        runBillGeneration();
        $oakProof = submitMemberProof($this->acme, $this->owner, $oakTenant, null, proofFile('oak.png', 50));
        $palmProof = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('oak-copy.png', 50));
        assignIn($this->acme, $agent, $this->palm);

        expect(proofAbilitiesOf($agent, $this->acme, storedProof($this->acme, $oakProof)))->toMatchArray(['view' => false, 'viewFile' => false, 'archive' => false])
            ->and(proofAbilitiesOf($agent, $this->acme, storedProof($this->acme, $palmProof)))->toMatchArray(['view' => true, 'viewFile' => true, 'archive' => true]);
        paymentsPage($agent, '/proofs/'.$oakProof->id.'/file')->assertNotFound();
        // The duplicate warning names no tenant the agent cannot see.
        paymentsPage($agent, '/'.$palmProof->paymentId)->assertOk()->assertSee('data-test="proof-duplicate"', false)
            ->assertSee('A proof of a tenant you cannot open')->assertDontSee('Oak Tenant')->assertDontSee('/payments/'.$oakProof->payment_id, false);

        expect(fn () => submitMemberProof($this->acme, $agent, $oakTenant, null, proofFile('x.png', 51)))->toThrow(ValidationException::class, 'Choose a tenant.');
        submitMemberProof($this->acme, $agent, $this->kemi, $this->bill, proofFile('y.png', 52));
        expect(proofsOf($this->acme))->toHaveCount(3);
    });
});

it('enforces the ARCHITECTURE.md § 5 role rules in PaymentProofPolicy', function (OrganizationRole $role, bool $allowed) {
    $proof = storedProof($this->acme, submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill));
    $user = joinAs(User::factory()->create(), $this->acme, $role);

    expect(proofAbilitiesOf($user, $this->acme, $proof))->toBe([
        'viewAny' => $allowed, 'view' => $allowed, 'viewFile' => $allowed, 'create' => $allowed,
        'archive' => $allowed, 'update' => false, 'delete' => false, 'forceDelete' => false,
    ]);
})->with([
    'org-owner' => [OrganizationRole::Owner, true],
    'org-admin' => [OrganizationRole::Admin, true],
    'agent' => [OrganizationRole::Agent, true],
    'property-owner' => [OrganizationRole::PropertyOwner, false],
    'tenant' => [OrganizationRole::Tenant, false],
]);

it('answers 404 for another organization\'s proof guessed by ID, on its file, its payment and every action', function () {
    $foreign = submitMemberProof($this->other, $this->otherOwner, $this->stranger, $this->foreignBill, proofFile('foreign.png', 60));
    $ours = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('ours.png', 61));

    paymentsPage($this->owner, '/proofs/'.$foreign->id.'/file')->assertNotFound();
    paymentsPage($this->owner, '/proofs/'.$foreign->id.'/thumb')->assertNotFound();
    paymentsPage($this->owner, '/'.$foreign->payment_id)->assertNotFound();
    paymentsPage($this->owner, '/proofs/999999/file')->assertNotFound();
    paymentsPage($this->owner, '/proofs/abc/file')->assertNotFound();
    // Their proof under their slug for our owner (not a member), ours under theirs for their owner.
    paymentsPage($this->owner, '/proofs/'.$foreign->id.'/file', 'other-realty')->assertNotFound();
    paymentsPage($this->otherOwner, '/proofs/'.$ours->proofId.'/file', 'other-realty')->assertNotFound();
    paymentsPage($this->otherOwner, '/proofs/'.$ours->proofId.'/file')->assertNotFound();

    // Never listed with ours, never reachable by the writer from here.
    expect(listedPayments(paymentsPage($this->owner, '?source=proof_upload')))->toBe([$ours->paymentId])
        ->and(fn () => inOrganization($this->acme, fn () => PaymentProof::query()->findOrFail($foreign->id)))->toThrow(ModelNotFoundException::class)
        ->and(inOrganization($this->acme, fn (): bool => Gate::forUser($this->owner)->allows('archive', storedProof($this->other, $foreign))))->toBeFalse()
        ->and(fn () => submitMemberProof($this->acme, $this->owner, $this->stranger, null, proofFile('z.png', 62)))->toThrow(ValidationException::class, 'Choose a tenant.')
        ->and(fn () => submitMemberProof($this->acme, $this->owner, $this->kemi, $this->foreignBill, proofFile('z.png', 62)))->toThrow(ValidationException::class);

    // A tampered proof id on a page answers 404 too: the page loads its payment through the scope.
    $page = paymentsSnapshot($this->owner, '/'.$ours->paymentId);
    config(['app.debug' => false]);
    paymentsUpdate($this->owner, str_replace('"paymentId":'.$ours->paymentId, '"paymentId":'.$foreign->payment_id, $page), [], [['archiveProof']])->assertStatus(419);
    expect(storedProof($this->other, $foreign)->isArchived())->toBeFalse();
});

describe('sub: impersonation blocks money actions', function () {
    it('lets impersonating staff read a proof\'s details, but never upload, archive or open the file, in either mode', function (ImpersonationMode $mode) {
        $submitted = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG.png', 70));
        $staff = staffMember();
        $handOff = startSupport($staff, $this->owner, $this->acme, $mode);
        enterSupport($handOff)->assertRedirect('/o/acme-homes');

        $show = asSupport()->get('/o/acme-homes/payments/'.$submitted->paymentId)->assertOk()
            ->assertSee('data-test="payment-proof"', false)
            ->assertSee('Guaranty Trust Bank (GTBank)')
            ->assertSee('data-test="proof-file-hidden"', false)
            ->assertDontSee('/proofs/'.$submitted->proofId.'/file', false)
            ->assertDontSee('data-test="archive-proof"', false);
        asSupport()->get('/o/acme-homes/payments')->assertOk()->assertDontSee('data-test="upload-proof"', false);
        asSupport()->get('/o/acme-homes/payments/proofs/create')->assertForbidden();
        asSupport()->get('/o/acme-homes/payments/proofs/'.$submitted->proofId.'/file')->assertForbidden();
        asSupport()->get('/o/acme-homes/payments/proofs/'.$submitted->proofId.'/thumb')->assertForbidden();
        LivewireHttp::update(asSupport(), LivewireHttp::snapshot($show), ['archiveReason' => 'Support tidy-up'], [['archiveProof']])->assertForbidden();

        expect(storedProof($this->acme, $submitted)->isArchived())->toBeFalse()
            ->and(proofsOf($this->acme))->toHaveCount(1);

        if ($mode === ImpersonationMode::Write) {
            expect(AuditEntry::query()->where('event', 'impersonation.blocked')->where('subject_id', $handOff->session->id)->exists())->toBeTrue();
        }
    })->with([
        'read-only' => [ImpersonationMode::ReadOnly],
        'write' => [ImpersonationMode::Write],
    ]);

    it('refuses a proof from the portal while staff impersonate that user', function () {
        // An agent of the organization who also rents a flat in it.
        $agent = joinAs(User::factory()->create(['name' => 'Tola Ade', 'email' => 'tola@example.test']), $this->acme, OrganizationRole::Agent);
        proofPortalAccount($this->acme, $this->tola, $agent);
        $handOff = startSupport(staffMember(), $agent, $this->acme, ImpersonationMode::Write);

        app(CurrentImpersonation::class)->begin($handOff->session);
        expect(fn () => submitTenantProof($this->acme, $agent, $this->tolasBill))->toThrow(AuthorizationException::class)
            ->and(fn () => submitMemberProof($this->acme, $agent, $this->tola, $this->tolasBill))->toThrow(AuthorizationException::class)
            ->and(inOrganization($this->acme, fn (): bool => app(PaymentProofs::class)->maySubmit($agent, $this->tolasBill->id)))->toBeFalse()
            ->and(proofsOf($this->acme))->toHaveCount(0);

        // Out of the support session, the same person submits it.
        app(CurrentImpersonation::class)->clear();
        submitTenantProof($this->acme, $agent, $this->tolasBill, proofFile('t.png', 71), ['amount' => '80,000']);
        expect(proofsOf($this->acme))->toHaveCount(1);
    });
});

it('sub: still takes a tenant\'s proof while the organization is suspended, while its members only read', function () {
    $earlier = submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, proofFile('earlier.png', 80), ['amount' => '10,000']);
    $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

    // The portal keeps accepting proofs (ONBOARDING_COMPLIANCE.md § 3, § 5.5).
    $submitted = submitTenantProof($this->acme, $this->kemiUser, $this->bill, proofFile('IMG.png', 81));
    expect(proofPayment($this->acme, $submitted)->status)->toBe(PaymentStatus::PendingConfirmation)
        ->and(proofsOf($this->acme))->toHaveCount(2);

    // Members read the proof and open its file; they upload, archive and confirm nothing until it is reactivated.
    $page = paymentsPage($this->owner, '/'.$submitted->paymentId)->assertOk()->assertSee('data-test="payment-proof"', false)->assertSee('data-test="proof-open"', false)
        ->assertDontSee('data-test="archive-proof"', false)->assertDontSee('data-test="confirm-payment"', false);
    paymentsPage($this->owner, '/proofs/'.$submitted->proofId.'/file')->assertRedirect();
    paymentsPage($this->owner, '/proofs/create')->assertForbidden();
    expect(fn () => submitMemberProof($this->acme, $this->owner, $this->kemi, $this->bill, proofFile('m.png', 82)))->toThrow(AuthorizationException::class)
        ->and(fn () => inOrganization($this->acme, fn () => app(ProofSubmissions::class)->archive($this->owner, storedProof($this->acme, $earlier), 'Duplicate')))->toThrow(AuthorizationException::class);
    paymentsUpdate($this->owner, LivewireHttp::snapshot($page), ['archiveReason' => 'Duplicate'], [['archiveProof']])->assertForbidden();
    expect(storedProof($this->acme, $earlier)->isArchived())->toBeFalse()
        ->and(proofsOf($this->acme))->toHaveCount(2);
});
