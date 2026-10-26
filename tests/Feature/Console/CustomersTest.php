<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Notifications\OrganizationReactivatedNotice;
use App\Notifications\OrganizationSuspendedNotice;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerChangeRefused;
use App\Platform\Customers\CustomerUser;
use App\Platform\Customers\OrganizationSuspension;
use App\Platform\Filament\Resources\Customers\Pages\ListCustomers;
use App\Platform\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Platform\Filament\Resources\Customers\RelationManagers\HistoryRelationManager;
use App\Platform\Filament\Resources\Customers\RelationManagers\MembersRelationManager;
use App\Platform\Filament\Resources\CustomerUsers\Pages\ListCustomerUsers;
use App\Platform\Filament\Resources\CustomerUsers\Pages\ViewCustomerUser;
use App\Platform\Filament\Resources\CustomerUsers\RelationManagers\OrganizationsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * P01-06: Console → Customers (PLATFORM_CONSOLE.md § 1, ARCHITECTURE.md
 * § 6.9): the organizations list and page, the users lookup, and Suspend /
 * Reactivate with a reason, audited and emailed to the org-owners. The
 * enforcement in the workspace is in Tenancy/SuspendedOrganizationTest.
 */

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('console'));
    Notification::fake();
    seedStaffRoles();
    $this->console = '/'.config('app.admin_path');

    $this->acme = Organization::factory()->create([
        'name' => 'Acme Homes',
        'slug' => 'acme-homes',
        'type' => OrganizationType::Agency,
        'transaction_tier' => TransactionTier::Two,
        'trial_ends_at' => now()->addDays(10),
    ]);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test', 'phone' => '+2348031234567']), $this->acme);
    $this->agent = joinAs(User::factory()->create(['name' => 'Bayo Agent', 'email' => 'bayo@acme.test']), $this->acme, OrganizationRole::Agent);

    $this->other = Organization::factory()->create(['name' => 'Lekki Owners', 'slug' => 'lekki-owners', 'type' => OrganizationType::PropertyOwner]);
    $this->otherOwner = joinAs(User::factory()->create(['name' => 'Kemi Lekki', 'email' => 'kemi@lekki.test']), $this->other);
});

function customer(Organization $organization): Customer
{
    return Customer::query()->findOrFail($organization->id);
}

describe('who may open Customers', function () {
    it('opens the lists and pages for staff with customers.view', function (string $role) {
        $staff = staffNamed('Sade', [$role]);

        foreach (['/customers', '/customers/'.$this->acme->id, '/customer-users', '/customer-users/'.$this->owner->id] as $path) {
            $this->actingAs($staff, 'staff')->get($this->console.$path)->assertOk();
        }
    })->with(['super-admin', 'operations-manager', 'support-agent', 'compliance-officer', 'finance-admin']);

    it('refuses staff without customers.view, the analyst included (no personal data)', function (string $role) {
        $staff = staffNamed('Sade', [$role]);

        foreach (['/customers', '/customers/'.$this->acme->id, '/customer-users', '/customer-users/'.$this->owner->id] as $path) {
            $this->actingAs($staff, 'staff')->get($this->console.$path)->assertForbidden();
        }
    })->with(['analyst', 'technical-admin', 'content-manager']);

    it('never creates, edits or deletes customers from the console', function () {
        $staff = staffMember();

        expect(Gate::forUser($staff)->allows('create', Customer::class))->toBeFalse()
            ->and(Gate::forUser($staff)->allows('update', customer($this->acme)))->toBeFalse()
            ->and(Gate::forUser($staff)->allows('delete', customer($this->acme)))->toBeFalse()
            ->and(Gate::forUser($staff)->allows('update', CustomerUser::query()->findOrFail($this->owner->id)))->toBeFalse()
            ->and(fn () => customer($this->acme)->forceFill(['name' => 'x'])->save())->toThrow(LogicException::class);
    });
});

describe('the organizations list', function () {
    beforeEach(fn () => $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff'));

    it('shows type, status, tier, members, owner and dates', function () {
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([customer($this->acme), customer($this->other)])
            ->assertTableColumnStateSet('members_count', 2, customer($this->acme))
            ->assertTableColumnStateSet('owner_name', 'Ngozi Owner', customer($this->acme))
            ->assertTableColumnStateSet('owner_name', 'Kemi Lekki', customer($this->other))
            ->assertTableColumnFormattedStateSet('type', 'Property owner', customer($this->other))
            ->assertTableColumnFormattedStateSet('transaction_tier', 'Tier 2', customer($this->acme))
            ->assertTableColumnFormattedStateSet('status', 'Active', customer($this->acme))
            ->assertSee('ngozi@acme.test')
            ->assertSee($this->acme->trial_ends_at?->format('M j, Y') ?? '');
    });

    it('searches by name, slug and the owner\'s name, email or phone', function (string $search) {
        Livewire::test(ListCustomers::class)
            ->searchTable($search)
            ->assertCanSeeTableRecords([customer($this->acme)])
            ->assertCanNotSeeTableRecords([customer($this->other)]);
    })->with(['Acme', 'acme-homes', 'Ngozi', 'ngozi@acme', '8031234567']);

    it('filters by status, type and tier', function () {
        $this->other->update(['status' => OrganizationStatus::Suspended]);

        Livewire::test(ListCustomers::class)
            ->filterTable('status', [OrganizationStatus::Suspended->value])
            ->assertCanSeeTableRecords([customer($this->other)])
            ->assertCanNotSeeTableRecords([customer($this->acme)])
            ->resetTableFilters()
            ->filterTable('type', OrganizationType::Agency->value)
            ->assertCanSeeTableRecords([customer($this->acme)])
            ->assertCanNotSeeTableRecords([customer($this->other)])
            ->resetTableFilters()
            ->filterTable('transaction_tier', TransactionTier::Two->value)
            ->assertCanSeeTableRecords([customer($this->acme)])
            ->assertCanNotSeeTableRecords([customer($this->other)]);
    });

    it('pages on the server', function () {
        Organization::factory()->count(30)->create();

        $component = Livewire::test(ListCustomers::class)->assertCountTableRecords(32);

        expect($component->instance()->getTableRecords()->count())->toBe(25);
    });
});

describe('an organization\'s page and the users lookup', function () {
    beforeEach(fn () => $this->actingAs(staffNamed('Sade', ['support-agent']), 'staff'));

    it('lists the organization\'s users with their roles there', function () {
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertOk()
            ->assertSee('Acme Homes')
            ->assertSee('Tier 2');

        Livewire::test(MembersRelationManager::class, ['ownerRecord' => customer($this->acme), 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords(CustomerUser::query()->whereKey([$this->owner->id, $this->agent->id])->get())
            ->assertCanNotSeeTableRecords(CustomerUser::query()->whereKey([$this->otherOwner->id])->get())
            ->assertTableColumnStateSet('role_names', ['org-owner'], CustomerUser::query()->findOrFail($this->owner->id))
            ->assertTableColumnStateSet('role_names', ['agent'], CustomerUser::query()->findOrFail($this->agent->id));
    });

    it('finds a user by name, email or phone and shows every organization and role they hold', function () {
        joinAs($this->owner, $this->other, OrganizationRole::Admin);

        Livewire::test(ListCustomerUsers::class)
            ->searchTable('+2348031234567')
            ->assertCanSeeTableRecords(CustomerUser::query()->whereKey([$this->owner->id])->get())
            ->assertCanNotSeeTableRecords(CustomerUser::query()->whereKey([$this->agent->id, $this->otherOwner->id])->get())
            ->searchTable('bayo@')
            ->assertCanSeeTableRecords(CustomerUser::query()->whereKey([$this->agent->id])->get());

        Livewire::test(ViewCustomerUser::class, ['record' => $this->owner->id])->assertOk()->assertSee('ngozi@acme.test');

        $owner = CustomerUser::query()->findOrFail($this->owner->id);
        Livewire::test(OrganizationsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => ViewCustomerUser::class])
            ->assertCanSeeTableRecords([customer($this->acme), customer($this->other)])
            ->assertTableColumnStateSet('role_names', ['org-owner'], customer($this->acme))
            ->assertTableColumnStateSet('role_names', ['org-admin'], customer($this->other));
    });
});

describe('suspend and reactivate', function () {
    it('suspends with a reason from the console: audited with the staff member, owners emailed through the notifications queue', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionVisible('suspend')
            ->assertActionHidden('reactivate')
            ->callAction('suspend', data: ['reason' => 'Chargeback fraud under review', 'note_to_owner' => 'Please call our support line.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Acme Homes is suspended.')
            ->assertActionHidden('suspend')
            ->assertActionVisible('reactivate');

        $acme = $this->acme->fresh();
        expect($acme?->status)->toBe(OrganizationStatus::Suspended)
            ->and($acme?->suspended_at)->not->toBeNull()
            ->and($acme?->status_before_suspension)->toBe(OrganizationStatus::Active);

        $entry = AuditEntry::query()->where('event', 'organization.suspended')->sole();
        expect($entry->log_name)->toBe('customers')
            ->and($entry->subject_type)->toBe((new Organization)->getMorphClass())
            ->and($entry->subject_id)->toBe($this->acme->id)
            ->and($entry->causer_type)->toBe((new PlatformStaff)->getMorphClass())
            ->and($entry->causer_id)->toBe($operator->id)
            ->and($entry->reason)->toBe('Chargeback fraud under review')
            ->and($entry->properties['old'] ?? null)->toBe(['status' => 'active'])
            ->and($entry->properties['attributes'] ?? null)->toBe(['status' => 'suspended'])
            // One entry: the model's own change log is not doubled.
            ->and(AuditEntry::query()->where('subject_id', $this->acme->id)->where('subject_type', (new Organization)->getMorphClass())->where('event', 'updated')->exists())->toBeFalse();

        Notification::assertSentTo($this->owner, OrganizationSuspendedNotice::class, function (OrganizationSuspendedNotice $notice): bool {
            $mail = $notice->toMail($this->owner);
            $text = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

            return $notice->queue === 'notifications'
                && str_contains($mail->subject, 'Acme Homes has been suspended')
                && str_contains($text, 'Please call our support line.')
                && str_contains($text, 'view and export')
                && ! str_contains($text, 'Chargeback');
        });
        Notification::assertNotSentTo([$this->agent, $this->otherOwner], OrganizationSuspendedNotice::class);

        Livewire::test(HistoryRelationManager::class, ['ownerRecord' => customer($this->acme), 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$entry])
            ->assertSee('Tunde (staff)')
            ->assertSee('Chargeback fraud under review');

        $this->artisan('goproperty:audit-verify')->assertSuccessful();
    });

    it('requires a reason', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->actingAs($operator, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->callAction('suspend', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        expect(fn () => app(OrganizationSuspension::class)->suspend($operator, $this->acme, '   '))
            ->toThrow(CustomerChangeRefused::class, 'Give a reason');

        expect($this->acme->fresh()?->status)->toBe(OrganizationStatus::Active)
            ->and(AuditEntry::query()->where('log_name', 'customers')->exists())->toBeFalse();
        Notification::assertNothingSent();
    });

    it('does not let staff without customers.suspend suspend or reactivate', function (string $role) {
        $staff = staffNamed('Sade', [$role]);
        $this->actingAs($staff, 'staff');

        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertActionHidden('suspend')
            ->assertActionHidden('reactivate');

        expect(Gate::forUser($staff)->allows('suspend', customer($this->acme)))->toBeFalse()
            ->and(fn () => app(OrganizationSuspension::class)->suspend($staff, $this->acme, 'Trying'))->toThrow(CustomerChangeRefused::class);

        $this->acme->update(['status' => OrganizationStatus::Suspended]);
        expect(Gate::forUser($staff)->allows('reactivate', customer($this->acme)))->toBeFalse()
            ->and(fn () => app(OrganizationSuspension::class)->reactivate($staff, $this->acme, 'Trying'))->toThrow(CustomerChangeRefused::class);

        expect(AuditEntry::query()->where('log_name', 'customers')->exists())->toBeFalse();
        Notification::assertNothingSent();
    })->with(['support-agent', 'compliance-officer', 'finance-admin']);

    it('reactivates to the status it was suspended from, audited and emailed', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $this->acme->update(['status' => OrganizationStatus::Restricted]);
        app(OrganizationSuspension::class)->suspend($operator, $this->acme, 'Compliance hold');

        $this->actingAs($operator, 'staff');
        Livewire::test(ViewCustomer::class, ['record' => $this->acme->id])
            ->assertSee('Reactivation returns to')
            ->callAction('reactivate', data: ['reason' => 'Documents received'])
            ->assertHasNoActionErrors()
            ->assertNotified('Acme Homes is restricted again.');

        $acme = $this->acme->fresh();
        expect($acme?->status)->toBe(OrganizationStatus::Restricted)
            ->and($acme?->suspended_at)->toBeNull()
            ->and($acme?->status_before_suspension)->toBeNull();

        $entry = AuditEntry::query()->where('event', 'organization.reactivated')->sole();
        expect($entry->causer_id)->toBe($operator->id)
            ->and($entry->reason)->toBe('Documents received')
            ->and($entry->properties['attributes'] ?? null)->toBe(['status' => 'restricted']);

        Notification::assertSentTo($this->owner, OrganizationReactivatedNotice::class, fn (OrganizationReactivatedNotice $notice): bool => $notice->queue === 'notifications'
            && $notice->toMail($this->owner)->actionUrl === route('workspace.home', ['organization' => 'acme-homes']));
    });

    it('refuses to suspend twice, to suspend a cancelled organization, or to reactivate one that is not suspended', function () {
        $operator = staffNamed('Tunde', ['operations-manager']);
        $suspension = app(OrganizationSuspension::class);

        expect(fn () => $suspension->reactivate($operator, $this->acme, 'Nothing to do'))->toThrow(CustomerChangeRefused::class, 'not suspended');

        $suspension->suspend($operator, $this->acme, 'First');
        expect(fn () => $suspension->suspend($operator, $this->acme->fresh() ?? $this->acme, 'Again'))->toThrow(CustomerChangeRefused::class, 'cannot be suspended');

        $this->other->update(['status' => OrganizationStatus::Cancelled]);
        expect(fn () => $suspension->suspend($operator, $this->other, 'Closed'))->toThrow(CustomerChangeRefused::class)
            ->and(Gate::forUser($operator)->allows('suspend', customer($this->other)))->toBeFalse();
    });
});
