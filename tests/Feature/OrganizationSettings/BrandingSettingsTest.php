<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Branding\Branding;
use App\Branding\BrandingSettings;
use App\Documents\BrandingPreview;
use App\Enums\ChecklistItem;
use App\Enums\DocumentLayout;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Livewire\Settings\BrandingPage;
use App\Media\Media;
use App\Models\OnboardingChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Pdf\PdfManager;
use App\Platform\Impersonation\ImpersonationMode;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\LivewireHttp;

require_once __DIR__.'/../../Support/impersonation.php';
require_once __DIR__.'/../../Support/organization-settings.php';

/*
 * P01-05, Settings → Branding (BRANDING_DOCUMENTS.md § 1): server-side
 * rules (colour contrast, prefixes, plain text, the footer line by plan,
 * image uploads), the live preview built from unsaved values, saving and
 * its audit trail, the image route, who may see or change branding, and
 * the rendering rules of § 5 (the preview's HTML goes through dompdf).
 */

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('media');
    Notification::fake();
    seedOrganizationRoles();

    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd', 'slug' => 'acme-homes']);
    $this->owner = joinAs(User::factory()->create(['name' => 'Ngozi Owner', 'email' => 'ngozi@acme.test']), $this->acme);
    inOrganization($this->acme, fn () => app(OnboardingChecklist::class)->start());
});

afterEach(fn () => app(OrganizationContext::class)->clear());

/** The page's fields with valid values, overridden by $changes. */
function brandingInput(array $changes = []): array
{
    return $changes + [
        'tradingName' => 'Adeyemi Realty',
        'primaryColor' => '#0E6B5C',
        'accentColor' => '#F2B53A',
        'address' => '14 Admiralty Way, Lekki Phase 1, Lagos',
        'rcNumber' => 'RC 1234567',
        'phone' => '+234 803 000 0000',
        'email' => 'accounts@adeyemirealty.ng',
        'website' => 'adeyemirealty.ng',
        'invoicePrefix' => 'INV-ADR',
        'receiptPrefix' => 'RCT-ADR',
        'leasePrefix' => 'LS-ADR',
        'invoiceNotes' => 'Rent is payable in advance.',
        'receiptFooter' => 'Thank you.',
        'layout' => 'modern',
        'emailSenderName' => '',
        'hidePoweredBy' => false,
    ];
}

/** @return array<string, list<string>> */
function brandingErrors(Organization $organization, User $by, array $changes, array $uploads = []): array
{
    try {
        inOrganization($organization, fn () => app(BrandingSettings::class)->save($by, $organization, brandingInput($changes), $uploads));
    } catch (ValidationException $invalid) {
        return $invalid->errors();
    }

    return [];
}

function brandingLivewire(User $user, Organization $organization): mixed
{
    test()->actingAs($user);
    app(OrganizationContext::class)->set($organization);

    return Livewire::test(BrandingPage::class);
}

/** A real PNG of the given size. */
function pngUpload(string $name, int $width = 400, int $height = 160): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagefilledrectangle($image, 20, 20, $width - 20, $height - 20, (int) imagecolorallocate($image, 14, 107, 92));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

describe('acceptance: low-contrast colours rejected', function () {
    it('rejects a primary colour too light for white paper, on the server, saving nothing', function (string $colour) {
        $errors = brandingErrors($this->acme, $this->owner, ['primaryColor' => $colour]);

        expect($errors['primaryColor'][0] ?? '')->toContain('is too light')
            ->and($this->acme->fresh()->branding)->toBeNull();
    })->with(['#FFE600', '#7DD3FC', '#FDE68A', '#FFFFFF']);

    it('rejects an accent too close to the primary', function () {
        expect(brandingErrors($this->acme, $this->owner, ['accentColor' => '#0F766E'])['accentColor'][0] ?? '')->toContain('too close to the primary')
            ->and($this->acme->fresh()->branding)->toBeNull();
    });

    it('refuses a low-contrast colour sent straight to the server, past the page', function () {
        $snapshot = settingsPage($this->owner, 'branding');

        $response = LivewireHttp::update($this, $snapshot, ['primaryColor' => '#FFFF99'], [['save']])->assertOk();

        expect(LivewireHttp::errors($response)['primaryColor'][0] ?? '')->toContain('#FFFF99 is too light')
            ->and($this->acme->fresh()->branding)->toBeNull();
    });

    it('accepts a mid-contrast colour and uses a darker shade for text on it', function () {
        expect(brandingErrors($this->acme, $this->owner, ['primaryColor' => '#0891B2', 'accentColor' => '']))->toBe([]);

        $branding = Branding::fromOrganization($this->acme->fresh());
        $html = app(BrandingPreview::class)->invoice(app(BrandingPreview::class)->forWeb($this->acme, $branding));

        expect($branding->primaryColor)->toBe('#0891B2')
            ->and($html)->toContain('.topbar { height: 8px; background: #0891b2; }')
            ->and($html)->not->toContain('.items th { background: #0891b2;');
    });
});

describe('acceptance: the preview reflects unsaved changes', function () {
    it('renders the sample invoice and receipt from the unsaved form, and saves nothing until Save', function () {
        $page = brandingLivewire($this->owner, $this->acme)
            ->set('tradingName', 'Lekki Homes')
            ->set('primaryColor', '#7C2D12')
            ->set('invoicePrefix', 'BILL-LKH')
            ->set('receiptPrefix', 'PAID-LKH')
            ->set('invoiceNotes', 'Pay before the 5th of the month.')
            ->set('receiptFooter', 'Keep this receipt safe.')
            ->set('layout', 'classic');

        $page->assertSee('Lekki Homes')
            ->assertSee('background: #7c2d12;')
            ->assertSee('BILL-LKH-')
            ->assertSee('PAID-LKH-')
            ->assertSee('Pay before the 5th of the month.')
            ->assertSee('Keep this receipt safe.')
            ->assertSee('DejaVu Serif');

        // Nothing written yet: not the organization, not the audit log, not the checklist.
        expect($this->acme->fresh()->branding)->toBeNull()
            ->and(AuditEntry::query()->where('log_name', 'organization')->where('event', 'updated')->exists())->toBeFalse()
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Branding->value)->sole()->isDone()))->toBeFalse();

        $page->call('save')->assertHasNoErrors()->assertSee('Branding saved');

        $saved = Branding::fromOrganization($this->acme->fresh());
        expect($saved->tradingName)->toBe('Lekki Homes')
            ->and($saved->primaryColor)->toBe('#7C2D12')
            ->and($saved->invoicePrefix)->toBe('BILL-LKH')
            ->and($saved->layout)->toBe(DocumentLayout::Classic);
    });

    it('keeps showing the saved colour while a colour is half typed', function () {
        brandingLivewire($this->owner, $this->acme)
            ->set('primaryColor', '#7C2D1')
            ->assertSee('background: #0e6b5c;');
    });

    it('shows a chosen logo in the preview before it is saved, and the monogram without one', function () {
        $page = brandingLivewire($this->owner, $this->acme)->assertSee('class="monogram"');

        $page->set('logo', pngUpload('logo.png'))->assertHasNoErrors();
        expect($this->acme->fresh()->branding)->toBeNull();
    });
});

describe('saving branding', function () {
    it('saves every field, audits the change and ticks the checklist', function () {
        $this->actingAs($this->owner);
        expect(brandingErrors($this->acme, $this->owner, ['invoicePrefix' => 'inv-adr', 'emailSenderName' => 'Adeyemi Realty Accounts']))->toBe([]);

        $saved = Branding::fromOrganization($this->acme->fresh());
        expect($saved->invoicePrefix)->toBe('INV-ADR')
            ->and($saved->accentColor)->toBe('#F2B53A')
            ->and($saved->rcNumber)->toBe('RC 1234567')
            ->and($saved->senderName('GoProperty'))->toBe('Adeyemi Realty Accounts')
            ->and(inOrganization($this->acme, fn () => OnboardingChecklistItem::query()->where('item', ChecklistItem::Branding->value)->sole()->isDone()))->toBeTrue();

        $entry = AuditEntry::query()->where('log_name', 'organization')->where('event', 'updated')->latest('id')->firstOrFail();
        expect($entry->causer_id)->toBe($this->owner->id)
            ->and(data_get($entry->attribute_changes, 'attributes.branding.primary_color'))->toBe('#0E6B5C')
            ->and(data_get($entry->attribute_changes, 'old.branding'))->toBeNull();
    });

    it('validates prefixes, plain text and lengths on the server', function (array $changes, string $field, string $message) {
        expect(brandingErrors($this->acme, $this->owner, $changes)[$field][0] ?? '')->toContain($message)
            ->and($this->acme->fresh()->branding)->toBeNull();
    })->with([
        'prefix with a space' => [['invoicePrefix' => 'INV ADR'], 'invoicePrefix', 'capital letters and digits'],
        'prefix too long' => [['receiptPrefix' => 'RECEIPTS-ADEYEMI'], 'receiptPrefix', 'capital letters and digits'],
        'same prefix twice' => [['receiptPrefix' => 'INV-ADR'], 'invoicePrefix', 'its own prefix'],
        'markup in notes' => [['invoiceNotes' => '<script>alert(1)</script>'], 'invoiceNotes', 'plain text'],
        'notes too long' => [['invoiceNotes' => str_repeat('a', 601)], 'invoiceNotes', '600'],
        'footer too long' => [['receiptFooter' => str_repeat('a', 301)], 'receiptFooter', '300'],
        'unknown layout' => [['layout' => 'fancy'], 'layout', 'layout'],
        'bad colour' => [['primaryColor' => 'teal'], 'primaryColor', 'such as #0E6B5C'],
        'bad email' => [['email' => 'not-an-email'], 'email', 'email'],
        'bad website' => [['website' => 'javascript:alert(1)'], 'website', 'web address'],
    ]);

    it('lets only the Scale plan hide the "Generated with" line', function () {
        expect(brandingErrors($this->acme, $this->owner, ['hidePoweredBy' => true])['hidePoweredBy'][0] ?? '')->toContain('Scale plan');

        $this->acme->forceFill(['signup_plan_key' => 'scale'])->save();
        expect(brandingErrors($this->acme->fresh(), $this->owner, ['hidePoweredBy' => true]))->toBe([]);

        $branding = Branding::fromOrganization($this->acme->fresh());
        $html = app(BrandingPreview::class)->invoice(app(BrandingPreview::class)->forWeb($this->acme, $branding));
        expect($branding->hidePoweredBy)->toBeTrue()->and($html)->not->toContain('Generated with');
    });
});

describe('logo, signature and stamp uploads', function () {
    it('refuses files that are not PNG, JPEG or WebP images, whatever their name', function (string $role, UploadedFile $file, string $message) {
        expect(brandingErrors($this->acme, $this->owner, [], [$role => $file])[$role][0] ?? '')->toContain($message)
            ->and($this->acme->fresh()->branding)->toBeNull()
            ->and(Storage::disk('media')->allFiles())->toBe([])
            ->and(inOrganization($this->acme, fn () => Media::query()->count()))->toBe(0);
    })->with([
        'PHP renamed to .png' => fn () => ['logo', UploadedFile::fake()->createWithContent('logo.png', "<?php echo 'pwned';"), 'PNG, JPEG or WebP'],
        'SVG' => fn () => ['logo', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'PNG, JPEG or WebP'],
        'SVG renamed to .png' => fn () => ['stamp', UploadedFile::fake()->createWithContent('stamp.png', '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>'), 'PNG, JPEG or WebP'],
        'HTML renamed to .jpg' => fn () => ['signature', UploadedFile::fake()->createWithContent('sig.jpg', '<html><body>hi</body></html>'), 'PNG, JPEG or WebP'],
        'over 1 MB' => fn () => ['logo', UploadedFile::fake()->createWithContent('big.png', "\x89PNG\r\n\x1A\n".str_repeat('x', 1_100_000)), 'larger than 1 MB'],
        'logo too narrow' => fn () => ['logo', pngUpload('small.png', 120, 60), 'at least 300 pixels wide'],
    ]);

    it('refuses a bad file on the page as soon as it is chosen', function () {
        brandingLivewire($this->owner, $this->acme)
            ->set('logo', UploadedFile::fake()->createWithContent('logo.png', "<?php echo 'pwned';"))
            ->assertHasErrors('logo')
            ->call('save')
            ->assertHasErrors('logo');

        expect($this->acme->fresh()->branding)->toBeNull();
    });

    it('stores a re-encoded PNG and serves it only to members, the signature only to settings viewers', function () {
        $page = brandingLivewire($this->owner, $this->acme)
            ->set('logo', pngUpload('logo.png', 900, 300))
            ->set('signature', pngUpload('signature.png', 300, 120))
            ->call('save')
            ->assertHasNoErrors();

        // Stored in the media library: the organization's `branding` collection, on the media disk.
        $branding = Branding::fromOrganization($this->acme->fresh());
        $logo = inOrganization($this->acme, fn () => Media::query()->findOrFail($branding->logoMediaId));
        $signature = inOrganization($this->acme, fn () => Media::query()->findOrFail($branding->signatureMediaId));
        expect($this->acme->fresh()->logo_media_id)->toBe($logo->id)
            ->and($this->acme->fresh()->branding['logo_media_id'] ?? null)->toBe($logo->id)
            ->and($logo->organization_id)->toBe($this->acme->id)
            ->and($logo->model_type)->toBe(Organization::class)
            ->and($logo->model_id)->toBe($this->acme->id)
            ->and($logo->collection_name)->toBe('branding')
            ->and($logo->getCustomProperty('role'))->toBe('logo')
            ->and($signature->getCustomProperty('role'))->toBe('signature')
            ->and($logo->mime_type)->toBe('image/png')
            ->and(Storage::disk('media')->exists($logo->getPathRelativeToRoot()))->toBeTrue()
            ->and($logo->getPathRelativeToRoot())->toStartWith($this->acme->id.'/'.$logo->uuid.'/');

        // Scaled down to at most 800 px wide.
        $size = getimagesizefromstring((string) Storage::disk('media')->get($logo->getPathRelativeToRoot()));
        expect($size[0])->toBeLessThanOrEqual(800)->and($size['mime'])->toBe('image/png')
            ->and($logo->checksum())->toBe(hash('sha256', (string) Storage::disk('media')->get($logo->getPathRelativeToRoot())));

        $logoUrl = '/o/acme-homes/settings/branding/files/logo/'.$logo->uuid;
        $signatureUrl = '/o/acme-homes/settings/branding/files/signature/'.$signature->uuid;

        $this->actingAs($this->owner)->get($logoUrl)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->owner)->get($signatureUrl)->assertOk();
        $page->assertSee($logoUrl);

        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        $this->actingAs($agent)->get($logoUrl)->assertOk();
        $this->actingAs($agent)->get($signatureUrl)->assertNotFound();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get($logoUrl)->assertNotFound();

        // Another organization's member, at their own address, cannot reach Acme's file.
        $other = Organization::factory()->create(['slug' => 'other-realty']);
        $otherOwner = joinAs(User::factory()->create(), $other);
        $this->actingAs($otherOwner)->get('/o/other-realty/settings/branding/files/logo/'.$logo->uuid)->assertNotFound();

        // A signature's uuid does not open as a logo.
        $this->actingAs($this->owner)->get('/o/acme-homes/settings/branding/files/logo/'.$signature->uuid)->assertNotFound();

        // Removing the logo brings the monogram back; the file is kept for issued documents.
        brandingLivewire($this->owner, $this->acme->fresh())->set('removeLogo', true)->call('save')->assertHasNoErrors();
        expect(Branding::fromOrganization($this->acme->fresh())->logoMediaId)->toBeNull()
            ->and($this->acme->fresh()->logo_media_id)->toBeNull()
            ->and(Storage::disk('media')->exists($logo->getPathRelativeToRoot()))->toBeTrue()
            ->and(inOrganization($this->acme, fn () => Media::query()->whereKey($logo->id)->exists()))->toBeTrue();
    });
});

describe('who may see and change branding', function () {
    it('lets org-owners and administrators change branding, not agents', function () {
        $admin = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Admin);
        expect(brandingErrors($this->acme, $admin, ['tradingName' => 'Admin Edit']))->toBe([]);

        $agent = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Agent);
        $this->actingAs($agent)->get('/o/acme-homes/settings/branding')->assertForbidden();
        $this->actingAs($agent)->get('/o/acme-homes/settings/profile')->assertForbidden();
        expect(fn () => brandingErrors($this->acme, $agent, ['tradingName' => 'Agent Edit']))->toThrow(AuthorizationException::class)
            ->and(Branding::fromOrganization($this->acme->fresh())->tradingName)->toBe('Admin Edit');
    });

    it('answers 404 to tenants and strangers, and for another organization', function () {
        $tenant = joinAs(User::factory()->create(), $this->acme, OrganizationRole::Tenant);
        $this->actingAs($tenant)->get('/o/acme-homes/settings/branding')->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/o/acme-homes/settings/branding')->assertNotFound();

        Organization::factory()->create(['slug' => 'other-realty']);
        $this->actingAs($this->owner)->get('/o/other-realty/settings/branding')->assertNotFound();
    });

    it('shows branding but refuses to save it while the organization is suspended', function () {
        $snapshot = settingsPage($this->owner, 'branding');
        $this->acme->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->actingAs($this->owner)->get('/o/acme-homes/settings/branding')->assertOk()->assertDontSee('Save branding');
        LivewireHttp::update($this, $snapshot, ['tradingName' => 'Suspended Edit'], [['save']])->assertForbidden();

        expect($this->acme->fresh()->branding)->toBeNull();
    });

    it('lets read-only support staff look but not save', function () {
        seedStaffRoles();
        $snapshot = settingsPage($this->owner, 'branding');
        enterSupport(startSupport(staffMember(), $this->owner, $this->acme, ImpersonationMode::ReadOnly))->assertRedirect('/o/acme-homes');

        asSupport()->get('/o/acme-homes/settings/branding')->assertOk();
        freshRequest();
        LivewireHttp::update($this, $snapshot, ['tradingName' => 'Support Edit'], [['save']])->assertForbidden();

        expect($this->acme->fresh()->branding)->toBeNull();
    });
});

describe('rendering (BRANDING_DOCUMENTS.md § 5)', function () {
    // One A4 page for up to 8 lines is P03-05's acceptance: dompdf has no
    // Inter yet and its DejaVu Sans fallback sets taller lines.
    it('renders the sample invoice and receipt with dompdf in every layout', function (DocumentLayout $layout) {
        expect(brandingErrors($this->acme, $this->owner, ['layout' => $layout->value], ['logo' => pngUpload('logo.png'), 'stamp' => pngUpload('stamp.png', 300, 300)]))->toBe([]);
        $preview = app(BrandingPreview::class);
        $brand = $preview->forPdf($this->acme, Branding::fromOrganization($this->acme->fresh()));

        expect($brand->logoSrc)->toStartWith('data:image/png;base64,');

        foreach ([$preview->invoice($brand), $preview->receipt($brand)] as $html) {
            expect($html)->not->toMatch('/display:\s*(flex|grid)|var\(--/');
            $pdf = app(PdfManager::class)->renderer('dompdf')->render($html);

            expect($pdf)->toStartWith('%PDF-')
                ->and(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBeLessThanOrEqual(2);
        }
    })->with(DocumentLayout::cases());
});
