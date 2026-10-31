<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\SubscriptionStatus;
use App\Livewire\Settings\SubscriptionPage;
use App\Media\Media;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\Subscription;
use App\Models\User;
use App\Platform\Billing\BankTransferProof;
use App\Platform\Billing\BankTransferReview;
use App\Platform\Filament\Pages\Settings\BankTransferDetails;
use App\Platform\Filament\Resources\BankTransfers\Pages\ListBankTransfers;
use App\Platform\Impersonation\ImpersonationMode;
use App\Settings\SettingsStore;
use App\Subscriptions\SubscriptionService;
use App\Tenancy\OrganizationContext;
use App\Tenancy\OrganizationWriteGuard;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\SaasBilling\Contracts\BankTransferReviews;
use Modules\SaasBilling\Contracts\TransferReviewRefused;
use Modules\SaasBilling\Enums\InvoiceStatus;
use Modules\SaasBilling\Enums\PlatformPaymentStatus;
use Modules\SaasBilling\Jobs\ReconcilePlatformPayments;
use Modules\SaasBilling\Metering\SubscriptionMetering;
use Modules\SaasBilling\Models\PlatformInvoice;
use Modules\SaasBilling\Models\PlatformPayment;
use Modules\SaasBilling\Notifications\BankTransferRejected;
use Modules\SaasBilling\Notifications\PlatformInvoiceIssued;
use Modules\SaasBilling\Notifications\PlatformPaymentReceived;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../../../tests/Support/plans.php';
require_once __DIR__.'/../../../../tests/Support/organization-settings.php';
require_once __DIR__.'/../../../../tests/Support/impersonation.php';
require_once __DIR__.'/../../../../tests/Feature/Exports/helpers.php';
require_once __DIR__.'/metering-helpers.php';

/*
 * P06-04: paying subscription invoices by bank transfer into GoProperty's
 * own account (BILLING_PAYMENTS.md § 5; docs/plans/P06-subtasks.md
 * defaults 31–37). Acceptance: "Proof alone never marks an invoice paid;
 * confirmation is audited."
 *
 * The world: Acme on Starter with 30 units; its trial ended on 16
 * November with invoice PR-2026-000001 for 16–30 November: ₦12,000 + ₦900
 * VAT = ₦12,900, due 23 November. GoProperty's account: Zenith Bank,
 * 1012345678, GoProperty Technologies Ltd.
 */

const TRANSFER_INVOICE = 'PR-2026-000001';

const TRANSFER_INVOICE_KOBO = 1_290_000;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    Storage::fake('media');
    seedOrganizationRoles();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00', 'Africa/Lagos'));
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd', 'status' => OrganizationStatus::Active]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@adeyemi.ng']), $this->acme);
    $this->admin = joinAs(User::factory()->create(['name' => 'Bisi Admin']), $this->acme, OrganizationRole::Admin);
    withUnits($this->acme, 30);
    startTrial($this->acme, 'starter', 14);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:30', 'Africa/Lagos'));
    endTrials();
    withTransferAccount();
});

afterEach(fn () => exportsFreshRequest());

function withTransferAccount(): void
{
    $settings = app(SettingsStore::class);
    $settings->set('billing.transfer_bank', 'Zenith Bank');
    $settings->set('billing.transfer_account_name', 'GoProperty Technologies Ltd');
    $settings->set('billing.transfer_account_number', '1012345678');
}

/** A small PNG "screenshot of the transfer", different for each seed. */
function transferScreenshot(string $name = 'transfer.png', int $seed = 1): UploadedFile
{
    $image = imagecreatetruecolor(60, 40);
    imagefilledrectangle($image, 0, 0, 59, 39, (int) imagecolorallocate($image, ($seed * 37) % 256, ($seed * 71) % 256, ($seed * 113) % 256));
    imagestring($image, 2, 2, 2, 'NIP '.$seed, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

/** Settings → Subscription as a Livewire component, for $user in $organization. */
function subscriptionLivewire(User $user, Organization $organization): mixed
{
    exportsFreshRequest();
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::test(SubscriptionPage::class);
}

/** An owner presses "Send proof of transfer" on an invoice, fills the form and sends it. */
function sendTransferProof(User $user, Organization $organization, string $number = TRANSFER_INVOICE, array $changes = [], ?UploadedFile $file = null): mixed
{
    $page = subscriptionLivewire($user, $organization)->call('startTransfer', $number);

    // The temporary upload's signature is checked against the real clock.
    $now = now();
    test()->travelBack();
    $page->set('file', $file ?? transferScreenshot());
    test()->travelTo($now);

    foreach ($changes + ['senderBank' => 'Guaranty Trust Bank (GTBank)'] as $field => $value) {
        $page->set($field, $value);
    }

    return $page->call('sendTransfer');
}

/** @return list<PlatformPayment> */
function transfersFor(Organization $organization, string $number = TRANSFER_INVOICE): array
{
    return inOrganization($organization, fn (): array => array_values(PlatformPayment::query()
        ->where('method', PlatformPayment::BANK_TRANSFER)
        ->where('platform_invoice_id', PlatformInvoice::query()->where('number', $number)->sole()->id)
        ->orderBy('id')->get()->all()));
}

function transferInvoice(Organization $organization, string $number = TRANSFER_INVOICE): PlatformInvoice
{
    return inOrganization($organization, fn (): PlatformInvoice => PlatformInvoice::query()->where('number', $number)->sole());
}

function transferSubscription(Organization $organization): Subscription
{
    return inOrganization($organization, fn (): Subscription => Subscription::query()->sole());
}

/** The console's Bank transfers page as $staff. */
function bankTransfersConsole(PlatformStaff $staff): mixed
{
    Filament::setCurrentPanel(Filament::getPanel('console'));
    test()->flushSession();
    test()->actingAs($staff, 'staff');

    return Livewire::test(ListBankTransfers::class);
}

function proofRow(int $paymentId): BankTransferProof
{
    return BankTransferProof::withDetails(BankTransferProof::query())->findOrFail($paymentId);
}

it('acceptance: a proof alone never marks the invoice paid', function () {
    // The invoice is overdue: the subscription is past due.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:00', 'Africa/Lagos'));
    inOrganization($this->acme, fn () => app(SubscriptionService::class)->markPastDue());

    // Settings → Subscription shows GoProperty's account, with the invoice number as the narration.
    exportPage($this->owner, '/settings/subscription')->assertOk()
        ->assertSee('Or pay by bank transfer')
        ->assertSee('Zenith Bank')
        ->assertSee('1012345678')
        ->assertSee('GoProperty Technologies Ltd')
        ->assertSee('data-test="transfer-narration">'.TRANSFER_INVOICE, false)
        ->assertSee('data-test="start-transfer-'.TRANSFER_INVOICE.'"', false);

    // The form starts with the total, today and the organization's name.
    subscriptionLivewire($this->owner, $this->acme)->call('startTransfer', TRANSFER_INVOICE)
        ->assertSet('amount', '12,900')
        ->assertSet('transferredOn', '2026-11-24')
        ->assertSet('senderName', 'Adeyemi Realty Ltd')
        ->assertSet('narration', TRANSFER_INVOICE)
        ->assertSee('data-test="transfer-form"', false);

    sendTransferProof($this->owner, $this->acme, changes: ['transferredOn' => '2026-11-23'], file: transferScreenshot('NIP receipt.png', 3))
        ->assertHasNoErrors()
        ->assertSet('transferInvoice', null)
        ->assertSee('Proof sent for invoice '.TRANSFER_INVOICE);

    // A payment waiting for GoProperty's staff, for the invoice's total, with what the owner said and the file kept.
    [$proof] = transfersFor($this->acme);
    expect($proof->only(['status', 'method', 'sender_name', 'sender_bank', 'transfer_narration', 'initiated_by_user_id', 'paystack_reference']))->toEqual([
        'status' => PlatformPaymentStatus::Pending, 'method' => 'bank_transfer', 'sender_name' => 'Adeyemi Realty Ltd',
        'sender_bank' => 'Guaranty Trust Bank (GTBank)', 'transfer_narration' => TRANSFER_INVOICE, 'initiated_by_user_id' => $this->owner->id, 'paystack_reference' => null,
    ])
        ->and($proof->amount_kobo->kobo)->toBe(TRANSFER_INVOICE_KOBO)
        ->and($proof->stated_amount_kobo?->kobo)->toBe(TRANSFER_INVOICE_KOBO)
        ->and($proof->transferred_on?->toDateString())->toBe('2026-11-23')
        ->and($proof->reviewed_by_staff_id)->toBeNull();
    $media = inOrganization($this->acme, fn (): Media => Media::query()->findOrFail($proof->bank_transfer_proof_media_id));
    expect($media->collection_name)->toBe('platform_payment_proofs')
        ->and($media->getCustomProperty('sha256'))->toBe($proof->proof_sha256)
        ->and(Storage::disk('media')->exists($media->getPathRelativeToRoot()))->toBeTrue()
        // Evidence: never deleted.
        ->and(fn () => inOrganization($this->acme, fn () => $media->delete()))->toThrow(Exception::class);

    // The invoice stays open and the subscription past due — and nothing else that runs pays it either.
    app()->call([new ReconcilePlatformPayments, 'handle']);
    meterUnits();
    $this->travel(2)->hours();
    app()->call([new ReconcilePlatformPayments, 'handle']);

    expect(transferInvoice($this->acme)->status)->toBe(InvoiceStatus::Open)
        ->and(transferInvoice($this->acme)->paid_at)->toBeNull()
        ->and(transfersFor($this->acme)[0]->status)->toBe(PlatformPaymentStatus::Pending)
        ->and(transferSubscription($this->acme)->status)->toBe(SubscriptionStatus::PastDue)
        ->and(AuditEntry::query()->where('event', 'platform_invoice.paid')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'platform_payment.transfer_submitted')->sole()->causer_id)->toBe($this->owner->id);
    Http::assertNothingSent();
    Notification::assertNotSentTo($this->owner, PlatformPaymentReceived::class);

    // The page says it is being checked, and a second proof waits until this one is reviewed.
    exportPage($this->owner, '/settings/subscription')->assertOk()
        ->assertSee('Proof sent: GoProperty is checking it')
        ->assertDontSee('data-test="start-transfer-'.TRANSFER_INVOICE.'"', false);
    sendTransferProof($this->owner, $this->acme, file: transferScreenshot('again.png', 4))
        ->assertHasErrors(['invoice' => 'GoProperty has your proof for invoice '.TRANSFER_INVOICE.' already and is checking it. You will hear back once it is reviewed.']);
    expect(transfersFor($this->acme))->toHaveCount(1);
});

it('acceptance: staff confirmation pays the invoice, reactivates the subscription and is audited', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00', 'Africa/Lagos'));
    inOrganization($this->acme, function (): void {
        app(SubscriptionService::class)->markPastDue();
        app(SubscriptionService::class)->startGrace();
        app(SubscriptionService::class)->suspend();
    });
    expect($this->acme->fresh()->status)->toBe(OrganizationStatus::Suspended);

    // A suspended organization can still send a proof (it is how it becomes active again), uploads included.
    $guard = app(OrganizationWriteGuard::class);
    foreach (['_startUpload', '_finishUpload', '_uploadErrored', '_removeUpload', 'startTransfer', 'sendTransfer', 'cancelTransfer'] as $method) {
        expect($guard->methodAllowed(SubscriptionPage::class, $method))->toBeTrue();
    }
    expect($guard->methodAllowed(SubscriptionPage::class, 'changePlan'))->toBeFalse();
    sendTransferProof($this->owner, $this->acme)->assertHasNoErrors();
    [$proof] = transfersFor($this->acme);

    seedStaffRoles();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);

    // Confirming needs the tick that the money was found in GoProperty's account.
    bankTransfersConsole($funmi)
        ->assertCanSeeTableRecords([$proof->id])
        ->callTableAction('confirm', $proof->id, ['checked' => false])
        ->assertHasTableActionErrors(['checked']);
    expect(transferInvoice($this->acme)->status)->toBe(InvoiceStatus::Open);

    $this->travelTo(CarbonImmutable::parse('2026-12-10 11:15', 'Africa/Lagos'));
    bankTransfersConsole($funmi)
        ->callTableAction('confirm', $proof->id, ['checked' => true])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Confirmed: invoice '.TRANSFER_INVOICE.' is paid');

    $proof = transfersFor($this->acme)[0];
    expect($proof->status)->toBe(PlatformPaymentStatus::Confirmed)
        ->and($proof->reviewed_by_staff_id)->toBe($funmi->id)
        ->and($proof->reviewed_at?->getTimestamp())->toBe(CarbonImmutable::parse('2026-12-10 11:15', 'Africa/Lagos')->getTimestamp())
        ->and($proof->confirmed_at)->not->toBeNull()
        ->and($proof->paid_amount_kobo?->kobo)->toBe(TRANSFER_INVOICE_KOBO)
        ->and(transferInvoice($this->acme)->status)->toBe(InvoiceStatus::Paid)
        ->and(transferSubscription($this->acme)->status)->toBe(SubscriptionStatus::Active)
        ->and($this->acme->fresh()->status)->toBe(OrganizationStatus::Active);

    // One audit entry, the staff member as causer.
    $audit = AuditEntry::query()->where('log_name', SubscriptionMetering::LOG)->where('event', 'platform_invoice.paid')->sole();
    expect($audit->causer_type)->toBe((new PlatformStaff)->getMorphClass())
        ->and($audit->causer_id)->toBe($funmi->id)
        ->and($audit->subject_id)->toBe(transferInvoice($this->acme)->id)
        ->and($audit->properties->only(['invoice', 'method', 'payment_id', 'total_kobo', 'confirmed_by_staff_id', 'subscription_reactivated'])->all())->toEqual([
            'invoice' => TRANSFER_INVOICE, 'method' => 'bank_transfer', 'payment_id' => $proof->id, 'total_kobo' => TRANSFER_INVOICE_KOBO,
            'confirmed_by_staff_id' => $funmi->id, 'subscription_reactivated' => true,
        ]);

    // The owners get the receipt once; the administrator does not.
    Notification::assertSentToTimes($this->owner, PlatformPaymentReceived::class, 1);
    Notification::assertNotSentTo($this->admin, PlatformPaymentReceived::class);
    $mail = (string) (new PlatformPaymentReceived($this->acme->id, $proof->id))->toMail($this->owner)->render();
    expect($mail)->toContain('GoProperty received ₦12,900 on 10 December 2026 for invoice '.TRANSFER_INVOICE)
        ->and($mail)->toContain('Reference: Bank transfer.');

    // A confirmed proof is never reviewed again.
    expect(fn () => app(BankTransferReview::class)->reject($funmi, proofRow($proof->id), 'Changed my mind'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(BankTransferReviews::class)->confirm($funmi, $proof->id))->toThrow(TransferReviewRefused::class, 'This proof was reviewed already.');
    // The list shows waiting proofs first; a confirmed one, under its filter, has no review actions.
    bankTransfersConsole($funmi)->assertCanNotSeeTableRecords([$proof->id])
        ->filterTable('status', 'confirmed')
        ->assertCanSeeTableRecords([$proof->id])
        ->assertTableActionHidden('confirm', $proof->id)
        ->assertTableActionHidden('reject', $proof->id);
    expect(AuditEntry::query()->where('event', 'platform_invoice.paid')->count())->toBe(1);
});

it('rejects a proof with the reason the owners are told, keeps the invoice open, and takes another proof', function () {
    sendTransferProof($this->owner, $this->acme, changes: ['amount' => '12,000'], file: transferScreenshot('short.png', 5))->assertHasNoErrors();
    [$short] = transfersFor($this->acme);
    seedStaffRoles();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);

    // The console flags what the owner says was sent against the total; a reason is needed.
    expect(proofRow($short->id)->only(['invoice_number', 'invoice_total_kobo', 'invoice_status', 'stated_amount_kobo', 'same_file_count']))->toEqual([
        'invoice_number' => TRANSFER_INVOICE, 'invoice_total_kobo' => TRANSFER_INVOICE_KOBO, 'invoice_status' => 'open', 'stated_amount_kobo' => 1_200_000, 'same_file_count' => 0,
    ]);
    bankTransfersConsole($funmi)->callTableAction('reject', $short->id, ['reason' => ''])->assertHasTableActionErrors(['reason']);
    expect(fn () => app(BankTransferReviews::class)->reject($funmi, $short->id, ' x '))->toThrow(TransferReviewRefused::class);

    bankTransfersConsole($funmi)
        ->callTableAction('reject', $short->id, ['reason' => 'Only ₦12,000 reached our account; ₦12,900 is due. Send the other ₦900 and a proof of both.'])
        ->assertHasNoTableActionErrors();

    $short = transfersFor($this->acme)[0];
    expect($short->status)->toBe(PlatformPaymentStatus::Rejected)
        ->and($short->rejection_reason)->toBe('Only ₦12,000 reached our account; ₦12,900 is due. Send the other ₦900 and a proof of both.')
        ->and($short->reviewed_by_staff_id)->toBe($funmi->id)
        ->and(transferInvoice($this->acme)->status)->toBe(InvoiceStatus::Open)
        ->and(AuditEntry::query()->where('event', 'platform_payment.transfer_rejected')->sole()->causer_id)->toBe($funmi->id)
        ->and(AuditEntry::query()->where('event', 'platform_invoice.paid')->exists())->toBeFalse();

    Notification::assertSentToTimes($this->owner, BankTransferRejected::class, 1);
    Notification::assertNotSentTo($this->admin, BankTransferRejected::class);
    $mail = (new BankTransferRejected($this->acme->id, $short->id))->toMail($this->owner);
    expect($mail->subject)->toBe('Your bank transfer proof for GoProperty invoice '.TRANSFER_INVOICE.' was not accepted')
        ->and((string) $mail->render())->toContain('proof of a ₦12,000 bank transfer on 16 November 2026')
        ->and((string) $mail->render())->toContain('Only ₦12,000 reached our account')
        ->and((string) $mail->render())->toContain('is still open');

    // The page shows the reason and offers another proof; the same file again is flagged for staff, not refused.
    exportPage($this->owner, '/settings/subscription')->assertOk()
        ->assertSee('Proof not accepted')
        ->assertSee('Only ₦12,000 reached our account', false)
        ->assertSee('data-test="start-transfer-'.TRANSFER_INVOICE.'"', false);
    sendTransferProof($this->owner, $this->acme, file: transferScreenshot('short-again.png', 5))->assertHasNoErrors();
    [, $again] = transfersFor($this->acme);
    expect(proofRow($again->id)->same_file_count)->toBe(1)
        ->and($again->status)->toBe(PlatformPaymentStatus::Pending);
});

it('refuses to confirm a transfer for an invoice paid meanwhile, which staff then reject', function () {
    sendTransferProof($this->owner, $this->acme)->assertHasNoErrors();
    [$proof] = transfersFor($this->acme);

    // Paid by card in the meantime (Paystack, P06-03).
    inOrganization($this->acme, fn () => DB::table('platform_invoices')->where('number', TRANSFER_INVOICE)->update(['status' => 'paid', 'paid_at' => now()]));

    seedStaffRoles();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);
    bankTransfersConsole($funmi)
        ->callTableAction('confirm', $proof->id, ['checked' => true])
        ->assertNotified('Invoice '.TRANSFER_INVOICE.' is paid, so this transfer cannot pay it. Reject the proof with the reason instead.');
    expect(transfersFor($this->acme)[0]->status)->toBe(PlatformPaymentStatus::Pending)
        ->and(AuditEntry::query()->where('event', 'platform_invoice.paid')->exists())->toBeFalse();
    Notification::assertNotSentTo($this->owner, PlatformPaymentReceived::class);

    bankTransfersConsole($funmi)->callTableAction('reject', $proof->id, ['reason' => 'Paid by card on 16 November; we will refund this transfer.']);
    expect(transfersFor($this->acme)[0]->status)->toBe(PlatformPaymentStatus::Rejected);
});

it('checks the proof: the file, the amount, the day, an invoice that is open, and five a day', function () {
    sendTransferProof($this->owner, $this->acme, file: UploadedFile::fake()->createWithContent('transfer.png', "<?php system(\$_GET['c']); ?>"))->assertHasErrors(['file']);
    sendTransferProof($this->owner, $this->acme, changes: ['amount' => 'twelve thousand'])->assertHasErrors(['amount' => 'Give the amount in naira, for example 12,900.']);
    sendTransferProof($this->owner, $this->acme, changes: ['transferredOn' => '2026-11-17'])->assertHasErrors(['transferredOn' => 'The day of the transfer cannot be in the future.']);
    sendTransferProof($this->owner, $this->acme, changes: ['senderName' => ''])->assertHasErrors(['senderName']);
    subscriptionLivewire($this->owner, $this->acme)->call('startTransfer', 'PR-2026-999999')->assertNotFound();
    expect(transfersFor($this->acme))->toBe([]);

    // Five proofs a day, rejected one by one by staff in between, then no more until tomorrow.
    seedStaffRoles();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);
    foreach (range(1, 5) as $seed) {
        sendTransferProof($this->owner, $this->acme, file: transferScreenshot('t.png', 10 + $seed))->assertHasNoErrors();
        app(BankTransferReviews::class)->reject($funmi, transfersFor($this->acme)[$seed - 1]->id, 'Not in our account yet.');
    }
    sendTransferProof($this->owner, $this->acme, file: transferScreenshot('t.png', 20))->assertHasErrors(['file']);
    $this->travel(1)->day();
    sendTransferProof($this->owner, $this->acme, file: transferScreenshot('t.png', 21))->assertHasNoErrors();
    expect(transfersFor($this->acme))->toHaveCount(6);

    // A paid invoice takes no proof.
    inOrganization($this->acme, fn () => DB::table('platform_invoices')->where('number', TRANSFER_INVOICE)->update(['status' => 'paid', 'paid_at' => now()]));
    subscriptionLivewire($this->owner, $this->acme)->call('startTransfer', TRANSFER_INVOICE)->assertNotFound();
});

it('lets only owners send a proof: not administrators, not staff while impersonating, and not before GoProperty\'s account is set', function () {
    // Administrators see the account but cannot send a proof.
    exportPage($this->admin, '/settings/subscription')->assertOk()->assertSee('1012345678')->assertDontSee('data-test="start-transfer-'.TRANSFER_INVOICE.'"', false);
    subscriptionLivewire($this->admin, $this->acme)->call('startTransfer', TRANSFER_INVOICE)->assertForbidden();
    subscriptionLivewire($this->admin, $this->acme)->call('sendTransfer')->assertForbidden();

    // GoProperty staff impersonating an owner, even in write mode.
    seedStaffRoles();
    exportsFreshRequest();
    enterSupport(startSupport(staffMember(['name' => 'Sola Support', 'email' => 'sola@goproperty.ng']), $this->owner, $this->acme, ImpersonationMode::Write))->assertRedirect('/o/acme-homes');
    $page = asSupport()->get('/o/acme-homes/settings/subscription')->assertOk()->assertDontSee('data-test="start-transfer-'.TRANSFER_INVOICE.'"', false);
    asSupport();
    LivewireHttp::update(test(), LivewireHttp::snapshot($page), [], [['startTransfer', [TRANSFER_INVOICE]]])->assertForbidden();
    expect(transfersFor($this->acme))->toBe([]);
});

it('offers no bank transfer until GoProperty\'s account is set in the console, by finance staff, audited', function () {
    $settings = app(SettingsStore::class);
    foreach (['billing.transfer_bank', 'billing.transfer_account_name', 'billing.transfer_account_number'] as $key) {
        $settings->set($key, null);
    }
    exportPage($this->owner, '/settings/subscription')->assertOk()->assertDontSee('Or pay by bank transfer')->assertDontSee('data-test="start-transfer-', false);
    sendTransferProof($this->owner, $this->acme)->assertHasErrors(['invoice']);
    // The invoice email has no transfer line either.
    $issued = new PlatformInvoiceIssued($this->acme->id, transferInvoice($this->acme)->id);
    expect((string) $issued->toMail($this->owner)->render())->not->toContain('bank transfer');

    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $console = '/'.config('app.admin_path');

    foreach (['support-agent', 'technical-admin', 'operations-manager'] as $role) {
        $this->flushSession();
        $this->actingAs(staffNamed(ucfirst(str_replace('-', '', $role)), [$role]), 'staff');
        $this->get($console.'/settings/bank-transfer')->assertForbidden();
    }

    $this->flushSession();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);
    $this->actingAs($funmi, 'staff');
    $this->get($console.'/settings/bank-transfer')->assertOk()->assertSee('Bank transfer details');

    Livewire::test(BankTransferDetails::class)
        ->fillForm(['bank' => 'Zenith Bank', 'account_name' => '', 'account_number' => ''])
        ->call('mountAction', 'save')->assertHasErrors(['data.bank']);
    Livewire::test(BankTransferDetails::class)
        ->fillForm(['bank' => 'Zenith Bank', 'account_name' => 'GoProperty Technologies Ltd', 'account_number' => '10123'])
        ->call('mountAction', 'save')->assertHasErrors(['data.account_number' => 'The account number is 10 digits.']);
    Livewire::test(BankTransferDetails::class)
        ->fillForm(['bank' => 'Access Bank', 'account_name' => 'GoProperty Technologies Ltd', 'account_number' => '0690000031'])
        ->call('mountAction', 'save')->assertHasNoErrors()->assertNotified('Saved: open invoices show this account');

    expect($settings->get('billing.transfer_account_number'))->toBe('0690000031')
        ->and(AuditEntry::query()->where('log_name', 'settings')->where('event', 'updated')->where('causer_id', $funmi->id)->pluck('properties')->map(fn ($properties) => $properties['key'])->sort()->values()->all())
        ->toBe(['billing.transfer_account_name', 'billing.transfer_account_number', 'billing.transfer_bank']);

    exportPage($this->owner, '/settings/subscription')->assertOk()->assertSee('Access Bank')->assertSee('0690000031');
    expect((string) $issued->toMail($this->owner)->render())
        ->toContain('Or pay by bank transfer to Access Bank, account 0690000031 (GoProperty Technologies Ltd) with '.TRANSFER_INVOICE.' as the narration');
});

it('shows the proofs in the console to finance and invoice staff only, and opens the file through a short-lived link', function () {
    sendTransferProof($this->owner, $this->acme)->assertHasNoErrors();
    [$proof] = transfersFor($this->acme);

    Filament::setCurrentPanel(Filament::getPanel('console'));
    seedStaffRoles();
    $console = '/'.config('app.admin_path');

    foreach (['support-agent', 'technical-admin', 'operations-manager', 'content-manager', 'analyst', 'compliance-officer'] as $role) {
        $this->flushSession();
        $staff = staffNamed(ucfirst(str_replace('-', '', $role)), [$role]);
        $this->actingAs($staff, 'staff');
        $this->get($console.'/bank-transfers')->assertForbidden();
        expect(app(BankTransferReview::class)->fileUrl($staff, proofRow($proof->id)))->toBeNull()
            ->and(fn () => app(BankTransferReview::class)->confirm($staff, proofRow($proof->id)))->toThrow(AuthorizationException::class);
    }

    // The contract checks the permission too, whoever calls it.
    expect(fn () => app(BankTransferReviews::class)->confirm(staffNamed('Opeyemi Ops', ['operations-manager']), $proof->id))
        ->toThrow(TransferReviewRefused::class);

    $this->flushSession();
    $funmi = staffNamed('Funmi Finance', ['finance-admin']);
    $this->actingAs($funmi, 'staff');
    $this->get($console.'/bank-transfers')->assertOk()->assertSee('Adeyemi Realty Ltd')->assertSee(TRANSFER_INVOICE)->assertSee('Waiting for review');

    // The file through a signed link that expires.
    $url = app(BankTransferReview::class)->fileUrl($funmi, proofRow($proof->id));
    expect($url)->toContain('/media/')->toContain('signature=');
    $this->flushSession();
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->travel(20)->minutes();
    $this->get($url)->assertNotFound();

    // Nothing changes a proof except the review: the read model is read-only.
    expect(fn () => proofRow($proof->id)->delete())->toThrow(LogicException::class);
});
