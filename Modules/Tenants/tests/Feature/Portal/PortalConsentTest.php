<?php

declare(strict_types=1);

use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\Consents\ConsentDocument;
use App\Privacy\Consents\ConsentSource;
use App\Privacy\LegalDocuments;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * P09-02 (NDPA consent records): accepting a tenant portal invitation is
 * accepting GoProperty's terms and privacy policy, as the page says; both
 * are recorded with their versions and the organization.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner']), $this->acme);
    $this->tenant = tenantIn($this->acme, ['first_name' => 'Adaeze', 'last_name' => 'Okafor', 'email' => 'adaeze@example.ng', 'phone' => '+2348031234567']);
});

afterEach(fn () => tenantPagesFreshRequest());

it('records the terms and privacy policy accepted with the invitation', function () {
    $token = inviteByEmail($this->owner, $this->tenant);

    openPortalInvitation($token)->assertOk()->assertSee('By creating an account you agree to the', false);
    acceptPortalInvitation($token)->assertRedirect('/portal/welcome');

    $user = User::query()->where('email', 'adaeze@example.ng')->sole();
    $records = ConsentRecord::query()->where('user_id', $user->id)->orderBy('id')->get();

    expect($records->map(fn (ConsentRecord $record): array => [$record->document, $record->granted, $record->source, $record->organization_id])->all())->toBe([
        [ConsentDocument::Terms, true, ConsentSource::TenantInvitation, $this->acme->id],
        [ConsentDocument::Privacy, true, ConsentSource::TenantInvitation, $this->acme->id],
    ])
        ->and($records[1]->version)->toBe(app(LegalDocuments::class)->version(ConsentDocument::Privacy))
        ->and($records[0]->ip_address)->toBe('127.0.0.1');
});
