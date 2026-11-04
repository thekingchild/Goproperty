<?php

declare(strict_types=1);

use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\Consents\ConsentSource;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/helpers.php';

/*
 * P09-02 (NDPA consent records): accepting an owner portal invitation
 * records the terms and privacy policy accepted, with the organization.
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Adeyemi Realty Ltd']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada.owner@example.com']), $this->acme);
    $this->bisi = ownerIn($this->acme, ['type' => 'person', 'first_name' => 'Bisi', 'last_name' => 'Adeyinka', 'email' => 'bisi.adeyinka@example.com', 'phone' => '+2348052223333']);
});

afterEach(fn () => ownerAccessFreshRequest());

it('records the terms and privacy policy accepted with the invitation', function () {
    $token = inviteOwnerByEmail($this->owner, $this->bisi);
    acceptOwnerInvitation($token)->assertRedirect();

    $user = User::query()->where('email', 'bisi.adeyinka@example.com')->sole();

    expect(ConsentRecord::query()->where('user_id', $user->id)->pluck('source')->all())->toBe([ConsentSource::OwnerInvitation, ConsentSource::OwnerInvitation])
        ->and(ConsentRecord::query()->where('user_id', $user->id)->pluck('organization_id')->unique()->all())->toBe([$this->acme->id]);
});
