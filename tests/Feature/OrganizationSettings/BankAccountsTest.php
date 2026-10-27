<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Banking\AccountNameMismatch;
use App\Banking\BankAccountRefused;
use App\Banking\BankAccounts;
use App\Enums\BankNameCheck;
use App\Enums\ChecklistItem;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/impersonation.php';
require_once __DIR__.'/../../Support/organization-settings.php';

/*
 * P01-05, Settings → Payment collection (ONBOARDING_COMPLIANCE.md § 4.1):
 * bank accounts encrypted at rest, the account-name check with Paystack
 * (faked: matched, mismatched, not found, unavailable), primary account,
 * deactivate and remove, the audit trail, and who may see or change them
 * (org-owners only; never impersonating staff; read-only when suspended).
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Notification::fake();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    inOrganization($this->acme, fn () => app(OnboardingChecklist::class)->start());
});

afterEach(fn () => app(OrganizationContext::class)->clear());

describe('acceptance: bank numbers encrypted at rest', function () {
    it('stores the account number encrypted, decrypts it for the app, and never shows it whole', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        $account = addBankAccount($this->acme, $this->owner, '0123456789');

        $raw = DB::table('organization_bank_accounts')->where('id', $account->id)->first();

        expect($raw->account_number)->not->toBe('0123456789')
            ->and($raw->account_number)->not->toContain('0123456789')
            ->and(Crypt::decryptString($raw->account_number))->toBe('0123456789')
            ->and($raw->account_number_last4)->toBe('6789')
            ->and($raw->account_fingerprint)->not->toContain('0123456789')
            ->and(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->findOrFail($account->id))->account_number)->toBe('0123456789')
            ->and($account->toArray())->not->toHaveKey('account_number')
            ->and($account->masked())->toBe('···6789');

        // The page shows it masked; the audit log never holds it.
        $this->actingAs($this->owner)->get('/o/acme-homes/settings/collections')
            ->assertOk()->assertSee('···6789')->assertDontSee('0123456789');
        expect(DB::table('activity_log')->where('properties', 'like', '%0123456789%')->exists())->toBeFalse()
            ->and(DB::table('activity_log')->where('description', 'like', '%0123456789%')->exists())->toBeFalse();
    });
});

describe('the account-name check', function () {
    it('saves an account the bank names as given, with the bank\'s spelling, as primary', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        $account = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.paystack.co/bank/resolve?')
            && $request['account_number'] === '0123456789'
            && $request['bank_code'] === '058'
            && $request->hasHeader('Authorization', 'Bearer sk_test_goproperty_platform'));

        expect($account->name_check_status)->toBe(BankNameCheck::Matched)
            ->and($account->account_name)->toBe('ADEYEMI REALTY LTD')
            ->and($account->name_check_result)->toBe('ADEYEMI REALTY LTD')
            ->and($account->name_checked_at)->not->toBeNull()
            ->and($account->is_default)->toBeTrue()
            ->and($account->bank_name)->toBe('Guaranty Trust Bank (GTBank)')
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Collections->value)->sole()->isDone()))->toBeTrue();

        $entry = AuditEntry::query()->where('event', 'bank_account.added')->sole();
        expect($entry->log_name)->toBe('payment_settings')
            ->and($entry->causer_id)->toBe($this->owner->id)
            ->and($entry->getProperty('account'))->toBe('···6789')
            ->and($entry->getProperty('name_check'))->toBe('matched');
    });

    it('refuses an account the bank names differently, saving nothing, and offers the bank\'s name', function () {
        paystackResolves('TUNDE BAKARE');

        expect(fn () => addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd'))
            ->toThrow(AccountNameMismatch::class, 'TUNDE BAKARE');
        expect(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->count()))->toBe(0);

        // Through the page: the message, then "Use the bank's name" saves it.
        $snapshot = settingsPage($this->owner, 'collections');
        $response = LivewireHttp::update($this, $snapshot, ['bankCode' => '058', 'accountNumber' => '0123456789', 'accountName' => 'Adeyemi Realty Ltd'], [['save']])->assertOk();
        expect(LivewireHttp::errors($response)['accountName'][0] ?? '')->toContain('TUNDE BAKARE')
            ->and(LivewireHttp::html($response))->toContain('Use “TUNDE BAKARE”')
            ->and(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->count()))->toBe(0);

        LivewireHttp::update($this, LivewireHttp::next($response), [], [['useBankName']])->assertOk();
        expect(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->sole())->account_name)->toBe('TUNDE BAKARE');
    });

    it('refuses a number the bank does not know', function () {
        paystackNotFound();

        expect(fn () => addBankAccount($this->acme, $this->owner))->toThrow(BankAccountRefused::class, 'no account with this number');
        expect(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->count()))->toBe(0);
    });

    it('saves the account as "name not checked" when no lookup can be made, and checks it later', function () {
        // No key configured (a fresh install): nothing is sent.
        withoutPaystackKey();
        $account = addBankAccount($this->acme, $this->owner, '0123456789', 'Adeyemi Realty Ltd');
        Http::assertNothingSent();

        expect($account->name_check_status)->toBe(BankNameCheck::Unchecked)
            ->and($account->account_name)->toBe('Adeyemi Realty Ltd')
            ->and($account->usable())->toBeTrue()
            ->and($account->is_default)->toBeTrue();

        // Paystack down: still unchecked.
        paystackDown();
        $second = addBankAccount($this->acme, $this->owner, '0987654321', 'Adeyemi Realty Ltd', '044');
        expect($second->name_check_status)->toBe(BankNameCheck::Unchecked);

        // Later, with a key: "Check name" on the page.
        paystackResolves('ADEYEMI REALTY LTD');
        $snapshot = settingsPage($this->owner, 'collections');
        $response = LivewireHttp::update($this, $snapshot, [], [['checkName', [$account->id]]])->assertOk();
        expect(LivewireHttp::html($response))->toContain('The bank confirmed the name')
            ->and(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->findOrFail($account->id))->name_check_status)->toBe(BankNameCheck::Matched)
            ->and(AuditEntry::query()->where('event', 'bank_account.name_checked')->count())->toBe(1);
    });

    it('stops showing an account to tenants when a later check finds another name, and moves the primary', function () {
        withoutPaystackKey();
        $first = addBankAccount($this->acme, $this->owner, '0123456789');
        $second = addBankAccount($this->acme, $this->owner, '0987654321', 'Adeyemi Realty Ltd', '044');
        expect($first->is_default)->toBeTrue();

        paystackResolves('SOMEBODY ELSE');
        $result = inOrganization($this->acme, fn () => app(BankAccounts::class)->checkName($this->owner, $first));

        inOrganization($this->acme, function () use ($first, $second, $result) {
            expect($result->bankAccountName)->toBe('SOMEBODY ELSE')
                ->and(OrganizationBankAccount::query()->findOrFail($first->id)->name_check_status)->toBe(BankNameCheck::Mismatched)
                ->and(OrganizationBankAccount::query()->findOrFail($first->id)->usable())->toBeFalse()
                ->and(app(BankAccounts::class)->primary()?->id)->toBe($second->id);
        });
    });

    it('limits name lookups per organization', function () {
        paystackResolves('ADEYEMI REALTY LTD');

        foreach (range(1, BankAccounts::LOOKUPS_PER_HOUR) as $n) {
            addBankAccount($this->acme, $this->owner, '01234567'.str_pad((string) $n, 2, '0', STR_PAD_LEFT));
            inOrganization($this->acme, fn () => OrganizationBankAccount::query()->latest('id')->first()?->delete());
        }

        expect(fn () => addBankAccount($this->acme, $this->owner, '0999999999'))->toThrow(BankAccountRefused::class, 'Too many account checks');
        Http::assertSentCount(BankAccounts::LOOKUPS_PER_HOUR);
    });
});

describe('managing accounts', function () {
    it('refuses the same account twice and a malformed number', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        addBankAccount($this->acme, $this->owner, '0123456789');

        expect(fn () => addBankAccount($this->acme, $this->owner, '012 345 6789'))->toThrow(BankAccountRefused::class, 'already been added')
            ->and(fn () => addBankAccount($this->acme, $this->owner, '12345'))->toThrow(BankAccountRefused::class, '10-digit')
            ->and(fn () => addBankAccount($this->acme, $this->owner, '0123456780', 'Adeyemi Realty Ltd', '999'))->toThrow(BankAccountRefused::class, 'Choose a bank');
    });

    it('makes another account primary, deactivates, reactivates and removes (soft delete), each audited', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        $first = addBankAccount($this->acme, $this->owner, '0123456789');
        $second = addBankAccount($this->acme, $this->owner, '0987654321', 'Adeyemi Realty', '044');
        $snapshot = settingsPage($this->owner, 'collections');

        $response = LivewireHttp::update($this, $snapshot, [], [['makePrimary', [$second->id]]])->assertOk();
        inOrganization($this->acme, fn () => expect(app(BankAccounts::class)->primary()?->id)->toBe($second->id)
            ->and(OrganizationBankAccount::query()->where('is_default', true)->count())->toBe(1));

        $response = LivewireHttp::update($this, LivewireHttp::next($response), [], [['deactivate', [$second->id]]])->assertOk();
        inOrganization($this->acme, fn () => expect(app(BankAccounts::class)->primary()?->id)->toBe($first->id)
            ->and(OrganizationBankAccount::query()->findOrFail($second->id)->active)->toBeFalse());

        $response = LivewireHttp::update($this, LivewireHttp::next($response), [], [['reactivate', [$second->id]]])->assertOk();
        $response = LivewireHttp::update($this, LivewireHttp::next($response), [], [['deactivate', [$second->id]]])->assertOk();
        LivewireHttp::update($this, LivewireHttp::next($response), [], [['remove', [$second->id]]])->assertOk();

        inOrganization($this->acme, fn () => expect(OrganizationBankAccount::query()->find($second->id))->toBeNull()
            ->and(OrganizationBankAccount::query()->withTrashed()->findOrFail($second->id)->deleted_at)->not->toBeNull());
        expect(DB::table('organization_bank_accounts')->where('id', $second->id)->exists())->toBeTrue()
            ->and(AuditEntry::query()->where('log_name', 'payment_settings')->orderBy('id')->pluck('event')->all())->toBe([
                'bank_account.added', 'bank_account.added', 'bank_account.made_primary', 'bank_account.deactivated',
                'bank_account.reactivated', 'bank_account.deactivated', 'bank_account.removed',
            ]);
    });

    it('edits an account, checking the new details, and keeps the number when none is typed', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        $account = addBankAccount($this->acme, $this->owner, '0123456789');
        $snapshot = settingsPage($this->owner, 'collections');

        $response = LivewireHttp::update($this, $snapshot, [], [['edit', [$account->id]]])->assertOk();
        expect(LivewireHttp::html($response))->not->toContain('0123456789');
        LivewireHttp::update($this, LivewireHttp::next($response), ['bankCode' => '057', 'accountNumber' => '', 'accountName' => 'Adeyemi Realty'], [['save']])->assertOk();

        $saved = inOrganization($this->acme, fn () => OrganizationBankAccount::query()->findOrFail($account->id));
        expect($saved->bank_code)->toBe('057')
            ->and($saved->bank_name)->toBe('Zenith Bank')
            ->and($saved->account_number)->toBe('0123456789')
            ->and(AuditEntry::query()->where('event', 'bank_account.updated')->sole()->getProperty('before'))->toMatchArray(['account' => '···6789']);
    });
});

describe('who may see and change bank accounts', function () {
    it('opens the page to org-owners only', function (OrganizationRole $role) {
        $member = joinAs(User::factory()->create(), $this->acme, $role);

        $this->actingAs($member)->get('/o/acme-homes/settings/collections')->assertForbidden();
        inOrganization($this->acme, fn () => expect(Gate::forUser($member)->allows('viewAny', OrganizationBankAccount::class))->toBeFalse()
            ->and(Gate::forUser($member)->allows('create', OrganizationBankAccount::class))->toBeFalse());
        expect(fn () => addBankAccount($this->acme, $member))->toThrow(AuthorizationException::class);
    })->with([
        'administrator' => OrganizationRole::Admin,
        'agent' => OrganizationRole::Agent,
    ]);

    it('answers 404 to tenants, property owners and strangers', function (?OrganizationRole $role) {
        $user = User::factory()->create();

        if ($role !== null) {
            joinAs($user, $this->acme, $role);
        }

        $this->actingAs($user)->get('/o/acme-homes/settings/collections')->assertNotFound();
    })->with([
        'tenant' => OrganizationRole::Tenant,
        'property owner' => OrganizationRole::PropertyOwner,
        'stranger' => null,
    ]);

    it('never reaches another organization\'s accounts', function () {
        paystackResolves('OTHER REALTY LTD');
        $other = Organization::factory()->create(['slug' => 'other-realty']);
        $otherOwner = joinAs(User::factory()->create(), $other);
        $theirs = addBankAccount($other, $otherOwner, '0555555555', 'Other Realty Ltd');

        $snapshot = settingsPage($this->owner, 'collections');
        foreach (['edit', 'checkName', 'makePrimary', 'deactivate', 'remove'] as $method) {
            LivewireHttp::update($this, $snapshot, [], [[$method, [$theirs->id]]])->assertNotFound();
        }

        $this->actingAs($this->owner)->get('/o/other-realty/settings/collections')->assertNotFound();
        expect(inOrganization($other, fn () => OrganizationBankAccount::query()->findOrFail($theirs->id))->active)->toBeTrue();
    });

    it('hides bank accounts from impersonating staff and refuses every change, in both modes', function (ImpersonationMode $mode) {
        seedStaffRoles();
        paystackResolves('ADEYEMI REALTY LTD');
        $account = addBankAccount($this->acme, $this->owner, '0123456789');
        $snapshot = settingsPage($this->owner, 'collections');

        enterSupport(startSupport(staffMember(['name' => 'Ada Okafor', 'email' => 'ada@goproperty.ng']), $this->owner, $this->acme, $mode))->assertRedirect('/o/acme-homes');

        asSupport()->get('/o/acme-homes/settings/collections')->assertForbidden()->assertDontSee('···6789')->assertDontSee('GTBank');
        freshRequest();
        LivewireHttp::update($this, $snapshot, ['bankCode' => '044', 'accountNumber' => '0987654321', 'accountName' => 'X'], [['save']])->assertForbidden();
        freshRequest();
        LivewireHttp::update($this, $snapshot, [], [['deactivate', [$account->id]]])->assertForbidden();

        // The Settings menu does not offer the page either.
        freshRequest();
        asSupport()->get('/o/acme-homes/settings/profile')->assertOk()->assertDontSee('Payment collection');

        expect(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->count()))->toBe(1)
            ->and(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->findOrFail($account->id))->active)->toBeTrue()
            ->and(AuditEntry::query()->where('event', 'impersonation.blocked')->count())->toBeGreaterThanOrEqual(2);
    })->with([
        'read-only' => ImpersonationMode::ReadOnly,
        'write' => ImpersonationMode::Write,
    ]);

    it('shows the accounts but refuses every change while the organization is suspended', function () {
        paystackResolves('ADEYEMI REALTY LTD');
        $account = addBankAccount($this->acme, $this->owner, '0123456789');
        $snapshot = settingsPage($this->owner, 'collections');
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)->get('/o/acme-homes/settings/collections')->assertOk()->assertSee('···6789')->assertDontSee('Check and add account');
        LivewireHttp::update($this, $snapshot, ['bankCode' => '044', 'accountNumber' => '0987654321', 'accountName' => 'Adeyemi Realty'], [['save']])->assertForbidden();
        LivewireHttp::update($this, $snapshot, [], [['deactivate', [$account->id]]])->assertForbidden();

        expect(inOrganization($this->acme, fn () => OrganizationBankAccount::query()->count()))->toBe(1)
            ->and(fn () => addBankAccount($this->acme, $this->owner, '0987654321'))->toThrow(AuthorizationException::class);
    });
});
