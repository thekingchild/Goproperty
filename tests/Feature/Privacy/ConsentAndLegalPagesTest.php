<?php

declare(strict_types=1);

use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\User;
use App\Privacy\Consents\ConsentDocument;
use App\Privacy\Consents\Consents;
use App\Privacy\Consents\ConsentSource;
use App\Privacy\LegalDocuments;
use App\Settings\SettingsStore;
use App\Theme\ThemeManager;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/members.php';

/*
 * P09-02: NDPA consent at signup and on joining an organization, product
 * email opt-in only, and the privacy policy and terms pages (versioned,
 * with the data protection officer and what a landlord sees).
 */

beforeEach(function () {
    Notification::fake();
    seedOrganizationRoles();
});

it('records the terms and privacy policy accepted at signup, with versions, IP address and browser, and no product email unless ticked', function () {
    $this->withHeader('User-Agent', 'Test browser 1.0')->post('/register', [
        'name' => 'Ada Adeyemi', 'email' => 'ada@adeyemi.ng', 'phone' => '0803 123 4567', 'password' => 'a-long-password-1',
    ])->assertRedirect(route('register.sent'));

    $ada = User::query()->where('email', 'ada@adeyemi.ng')->sole();
    $records = ConsentRecord::query()->where('user_id', $ada->id)->orderBy('id')->get();

    expect($records->pluck('document')->all())->toBe([ConsentDocument::Terms, ConsentDocument::Privacy])
        ->and($records->pluck('source')->unique()->all())->toBe([ConsentSource::Signup])
        ->and($records[0]->version)->toBe(app(LegalDocuments::class)->version(ConsentDocument::Terms))
        ->and($records[0]->version)->toStartWith('v-')
        ->and($records[0]->ip_address)->toBe('127.0.0.1')
        ->and($records[0]->user_agent)->toBe('Test browser 1.0')
        ->and($records[0]->organization_id)->toBeNull()
        ->and(app(Consents::class)->marketingEmail($ada))->toBeFalse();

    $this->post('/register', [
        'name' => 'Bola Bello', 'email' => 'bola@bello.ng', 'phone' => '0803 123 4568', 'password' => 'a-long-password-1', 'marketing_email' => '1',
    ])->assertRedirect(route('register.sent'));

    expect(app(Consents::class)->marketingEmail(User::query()->where('email', 'bola@bello.ng')->sole()))->toBeTrue();
});

it('records nothing for a signup with an address that already has an account', function () {
    $ada = User::factory()->create(['email' => 'ada@adeyemi.ng']);

    $this->post('/register', ['name' => 'Someone', 'email' => 'ada@adeyemi.ng', 'phone' => '0803 123 4569', 'password' => 'a-long-password-1', 'marketing_email' => '1'])
        ->assertRedirect(route('register.sent'));

    expect(ConsentRecord::query()->where('user_id', $ada->id)->exists())->toBeFalse();
});

it('records the terms accepted when a team invitation is accepted, with the organization', function () {
    $acme = Organization::factory()->create(['slug' => 'acme-homes', 'name' => 'Acme Homes']);
    $owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.ng']), $acme);
    $token = inviteMember($owner, 'chidi@acme.ng', ['agent']);
    membersFreshRequest();

    acceptAsNewPerson($token)->assertRedirect('/o/acme-homes');

    $chidi = User::query()->where('email', 'chidi@acme.ng')->sole();
    expect(ConsentRecord::query()->where('user_id', $chidi->id)->orderBy('id')->get()->map(fn (ConsentRecord $record): array => [$record->document->value, $record->source, $record->organization_id])->all())
        ->toBe([['terms', ConsentSource::MemberInvitation, $acme->id], ['privacy', ConsentSource::MemberInvitation, $acme->id]]);
    membersFreshRequest();
});

it('keeps product email off until the person switches it on, recording each change once', function () {
    $ada = User::factory()->create();
    $this->actingAs($ada);

    Livewire::test('pages::settings.notifications')->assertSet('productEmail', false)
        ->set('productEmail', true)->call('save')
        ->call('save');
    Livewire::test('pages::settings.notifications')->assertSet('productEmail', true)
        ->set('productEmail', false)->call('save');

    expect(ConsentRecord::query()->where('user_id', $ada->id)->where('document', ConsentDocument::MarketingEmail)->orderBy('id')->pluck('granted')->all())->toBe([true, false])
        ->and(ConsentRecord::query()->where('user_id', $ada->id)->pluck('source')->unique()->all())->toBe([ConsentSource::NotificationSettings]);
});

it('shows the privacy policy with what the landlord sees, the data protection officer and its version', function () {
    $this->get('/privacy')->assertOk()
        ->assertSee('Nigeria Data Protection Act 2023')
        ->assertSee('What the landlord of your building sees')
        ->assertSee('emergency contact')
        ->assertSee('KYC status')
        ->assertSee('last four characters')
        ->assertSee('your leases; your payments and receipts; and your deposit', false)
        ->assertSee('ask for the data protection officer')
        ->assertSee('Version '.app(LegalDocuments::class)->version(ConsentDocument::Privacy));

    $settings = app(SettingsStore::class);
    $settings->set('privacy.dpo_name', 'Funmi Adebayo');
    $settings->set('privacy.dpo_email', 'dpo@goproperty.ng');
    $settings->set('privacy.dpo_address', '1 Marina, Lagos');

    $this->get('/privacy')->assertOk()->assertSee('Funmi Adebayo')->assertSee('mailto:dpo@goproperty.ng', false)->assertSee('1 Marina, Lagos');
});

it('shows the terms naming the agency as the anti-money-laundering reporting entity, and gives each published text a new version', function () {
    $before = app(LegalDocuments::class)->version(ConsentDocument::Terms);

    $this->get('/terms')->assertOk()
        ->assertSee('reporting entity under the Money Laundering (Prevention and Prohibition) Act 2022')
        ->assertSee('GoProperty never receives, holds, splits or moves tenants')
        ->assertSee('Version '.$before)
        ->assertDontSee('What the landlord of your building sees');

    $themes = app(ThemeManager::class);
    $themes->saveDraft(['site.terms' => '<h2>Terms of service</h2><p>New terms.</p>']);
    $themes->publish();

    expect(app(LegalDocuments::class)->version(ConsentDocument::Terms))->not->toBe($before);
    $this->get('/terms')->assertSee('New terms.')->assertSee('Version '.app(LegalDocuments::class)->version(ConsentDocument::Terms));
});
