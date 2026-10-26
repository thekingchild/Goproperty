<?php

declare(strict_types=1);

use App\Models\PlatformStaff;
use App\Models\StaffStatus;
use App\Platform\Filament\Pages\Settings\BrandAppearance;
use App\Platform\Filament\Pages\Settings\CustomCode;
use App\Platform\Filament\Pages\Settings\PublicWebsite;
use App\Platform\Filament\Pages\Settings\SearchSharing;
use App\Platform\Filament\Pages\Settings\ThemeVersions;
use App\Settings\SettingsStore;
use App\Theme\ThemeManager;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * P00-09c: the console settings pages (PLATFORM_CONSOLE.md § 9.1–9.5, § 9.8):
 * drafts with a preview, publish, restore, image uploads, custom code with
 * step-up.
 */

beforeEach(function () {
    Storage::fake('brand');
    $this->staff = PlatformStaff::query()->create(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);
    $this->actingAs($this->staff, 'staff');
    Filament::setCurrentPanel('console');
    $this->console = '/'.config('app.admin_path');
});

function themes(): ThemeManager
{
    return app(ThemeManager::class);
}

it('opens every settings page for staff, and none for guests', function (string $slug) {
    $this->get($this->console.'/settings/'.$slug)->assertOk();

    auth('staff')->logout();
    $this->get($this->console.'/settings/'.$slug)->assertRedirect($this->console.'/login');
})->with(['brand', 'website', 'seo', 'custom-code', 'versions']);

it('saves brand changes as an audited draft that visitors do not see', function () {
    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => ['name' => 'Ile Homes', 'color_primary' => '#0f766e']])
        ->call('saveDraft')
        ->assertHasNoFormErrors()
        ->assertNotified('Draft saved');

    expect(themes()->draftValues())->toMatchArray(['theme.name' => 'Ile Homes', 'theme.color_primary' => '#0F766E'])
        ->and(themes()->publishedValues()['theme.name'])->toBe('GoProperty');

    $entry = Activity::query()->where('log_name', 'theme')->where('event', 'draft_saved')->sole();
    expect($entry->causer?->is($this->staff))->toBeTrue()
        ->and($entry->properties['changes']['theme.name'])->toBe(['old' => 'GoProperty', 'new' => 'Ile Homes'])
        ->and($entry->properties['changes'])->toHaveKeys(['theme.name', 'theme.color_primary'])
        ->and($entry->properties['changes'])->not->toHaveKey('theme.tagline');

    $this->get('/')->assertSee('GoProperty')->assertDontSee('Ile Homes');
});

it('reports form and registry rules on the matching fields', function (array $theme, string $field) {
    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => $theme])
        ->call('saveDraft')
        ->assertHasFormErrors(['theme.'.$field]);

    expect(themes()->hasDraft())->toBeFalse();
})->with([
    'plain text only (registry)' => [['tagline' => 'Rent <b>made</b> easy'], 'tagline'],
    'email (form)' => [['support_email' => 'not-an-email'], 'support_email'],
    'opacity range (form)' => [['hero_overlay_opacity' => 140], 'hero_overlay_opacity'],
]);

it('publishes the draft for everyone, and the console follows', function () {
    $page = Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => ['name' => 'Ile Homes', 'color_primary' => '#0f766e']])
        ->call('saveDraft');

    $page->callAction('publish')->assertNotified('Version 1 published');

    expect(themes()->hasDraft())->toBeFalse()
        ->and(themes()->publishedValues()['theme.name'])->toBe('Ile Homes');

    $this->get('/')->assertSee('Ile Homes')->assertSee('--color-primary-700:#0f766e', false);
    $this->get($this->console)->assertSee('Ile Homes console');
});

it('discards a draft and resets to the defaults', function () {
    themes()->saveDraft(['theme.name' => 'Ile Homes']);
    themes()->publish();
    themes()->saveDraft(['theme.tagline' => 'Draft tagline']);

    Livewire::test(BrandAppearance::class)->callAction('discard');
    expect(themes()->hasDraft())->toBeFalse();

    Livewire::test(BrandAppearance::class)->callAction('reset');
    expect(themes()->draftValues()['theme.name'])->toBe('GoProperty')
        ->and(themes()->publishedValues()['theme.name'])->toBe('Ile Homes');
});

it('warns when white text on the primary colour is hard to read', function () {
    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => ['color_primary' => '#FDE047']])
        ->call('saveDraft')
        ->assertNotified('White text on the primary colour is hard to read');
});

it('uploads a hero photo as two WebP sizes and shows it in the preview', function () {
    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => ['hero_image' => UploadedFile::fake()->image('hero.jpg', 3000, 1600)]])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $path = themes()->draftValues()['theme.hero_image'];
    expect($path)->toMatch('#^hero/[a-f0-9]{32}\.webp$#');

    $large = Storage::disk('brand')->get($path);
    $small = Storage::disk('brand')->get(str_replace('.webp', '-1200.webp', $path));
    expect(getimagesizefromstring($large))->toMatchArray([0 => 2400, 'mime' => 'image/webp'])
        ->and(getimagesizefromstring($small))->toMatchArray([0 => 1200, 'mime' => 'image/webp']);

    $hash = pathinfo($path, PATHINFO_FILENAME);
    $this->get($this->console.'/theme-preview/landing')->assertSee('has-photo', false)->assertSee('/brand/hero?v='.$hash, false);
    $this->get('/')->assertDontSee('has-photo', false);

    $this->get('/brand/hero?v='.$hash)->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/brand/hero?v='.$hash.'&w=1200')->assertOk();
});

it('refuses unsafe or mislabelled images', function (string $field, UploadedFile $file, string $message) {
    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => [$field => $file]])
        ->call('saveDraft')
        ->assertHasFormErrors(['theme.'.$field]);

    expect(themes()->hasDraft())->toBeFalse()
        ->and(Storage::disk('brand')->allFiles())->toBe([]);
})->with([
    'script in an SVG' => ['logo_light', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'scripts'],
    'handler in an SVG' => ['logo_dark', UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'), 'scripts'],
    'PHP named .png' => ['logo_light', UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'), 'Upload'],
    'icon not square' => ['icon', UploadedFile::fake()->image('icon.png', 600, 400), 'square'],
]);

it('stores a clean SVG logo and serves it sandboxed', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 30"><rect width="120" height="30" fill="#0f766e"/></svg>';

    Livewire::test(BrandAppearance::class)
        ->fillForm(['theme' => ['logo_dark' => UploadedFile::fake()->createWithContent('logo.svg', $svg)]])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    $path = themes()->draftValues()['theme.logo_dark'];
    $this->get('/brand/logo-dark?v='.pathinfo($path, PATHINFO_FILENAME))->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
});

it('deletes an image once nothing uses it, and keeps published ones', function () {
    // As in the browser: remove the current photo, then add the new one.
    $upload = fn (int $width) => Livewire::test(BrandAppearance::class)
        ->set('data.theme.hero_image', [])
        ->fillForm(['theme' => ['hero_image' => UploadedFile::fake()->image('hero.jpg', $width, 900)]])
        ->call('saveDraft');

    $upload(1600);
    $first = themes()->draftValues()['theme.hero_image'];
    themes()->publish();

    $upload(1700);
    $second = themes()->draftValues()['theme.hero_image'];
    expect(Storage::disk('brand')->exists($first))->toBeTrue('the published photo is kept');

    $upload(1800);
    $third = themes()->draftValues()['theme.hero_image'];

    expect(Storage::disk('brand')->exists($first))->toBeTrue()
        ->and(Storage::disk('brand')->exists($second))->toBeFalse('the replaced draft photo is deleted')
        ->and(Storage::disk('brand')->exists(str_replace('.webp', '-1200.webp', $second)))->toBeFalse()
        ->and(Storage::disk('brand')->exists($third))->toBeTrue();
});

it('edits the website text, FAQ and social links', function () {
    Livewire::test(PublicWebsite::class)
        ->fillForm(['site' => [
            'hero_heading' => 'Pay day.',
            'hero_trust' => ['Free for a month', 'No card', 'Leave anytime'],
            'social_links' => ['x' => '', 'linkedin' => 'https://www.linkedin.com/company/ile'],
            'announcement' => 'New: WhatsApp reminders',
        ]])
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    expect(themes()->draftValues())->toMatchArray([
        'site.hero_heading' => 'Pay day.',
        'site.hero_trust' => ['Free for a month', 'No card', 'Leave anytime'],
        'site.social_links' => ['linkedin' => 'https://www.linkedin.com/company/ile'],
        'site.announcement' => 'New: WhatsApp reminders',
    ]);
});

it('shows search and sharing changes in the Google preview and maps indexing', function () {
    Livewire::test(SearchSharing::class)
        ->fillForm(['seo' => ['title' => 'Ile Homes: rent made calm', 'description' => 'Rent collection for agencies.', 'indexing_enabled' => 'off']])
        ->assertSee('Ile Homes: rent made calm')
        ->call('saveDraft')
        ->assertHasNoFormErrors();

    expect(themes()->draftValues())->toMatchArray(['seo.title' => 'Ile Homes: rent made calm', 'seo.indexing_enabled' => false]);
});

it('asks for the password before changing the canonical origin', function () {
    $page = Livewire::test(SearchSharing::class)->fillForm(['seo' => ['canonical_origin' => 'https://goproperty.ng']]);

    $page->call('saveDraft')->assertNotified('Confirm it is you first');
    expect(themes()->hasDraft())->toBeFalse();

    $page->callAction('saveDraft', data: ['password' => 'wrong-password'])->assertNotified('The password is not right.');
    expect(themes()->hasDraft())->toBeFalse();

    $page->callAction('saveDraft', data: ['password' => 'x-long-password-1'])->assertNotified('Draft saved');
    expect(themes()->draftValues()['seo.canonical_origin'])->toBe('https://goproperty.ng');
});

it('applies custom code on save, after step-up, with a full audit', function () {
    $page = Livewire::test(CustomCode::class)
        ->fillForm(['custom_code' => ['head' => '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>', 'allowed_hosts' => ['www.googletagmanager.com', 'Connect.Facebook.net']]]);

    $page->callAction('save', data: ['password' => 'wrong-password'])->assertNotified('The password is not right.');
    expect(app(SettingsStore::class)->get('custom_code.head'))->toBeNull();

    $page->callAction('save', data: ['password' => 'x-long-password-1'])->assertNotified('Saved and live on the public pages');

    expect(app(SettingsStore::class)->get('custom_code.head'))->toContain('G-1')
        ->and(app(SettingsStore::class)->get('custom_code.allowed_hosts'))->toBe(['www.googletagmanager.com', 'connect.facebook.net'])
        ->and(Activity::query()->where('log_name', 'settings')->where('properties->key', 'custom_code.head')->sole()->properties['new'])->toContain('G-1');

    $this->get('/')->assertSee('gtag/js?id=G-1', false);

    // Within ten minutes no second confirmation is asked for.
    Livewire::test(CustomCode::class)
        ->fillForm(['custom_code' => ['css' => '.hero__title{color:red}']])
        ->callAction('save')
        ->assertNotified('Saved and live on the public pages');
});

it('refuses custom CSS that closes the style block', function () {
    $this->withSession(['console.step_up_at' => now()->getTimestamp()]);

    Livewire::test(CustomCode::class)
        ->fillForm(['custom_code' => ['css' => 'a{}</style><script>alert(1)</script>']])
        ->callAction('save')
        ->assertHasFormErrors(['custom_code.css']);

    expect(app(SettingsStore::class)->get('custom_code.css'))->toBeNull();
});

it('lists versions and restores one as a draft', function () {
    themes()->saveDraft(['theme.name' => 'First Name']);
    themes()->publish();
    themes()->saveDraft(['theme.name' => 'Second Name']);
    themes()->publish();

    Livewire::test(ThemeVersions::class)
        ->assertSee('Live')
        ->callAction('restore', arguments: ['version' => 1]);

    expect(themes()->draftValues()['theme.name'])->toBe('First Name')
        ->and(themes()->publishedValues()['theme.name'])->toBe('Second Name');
});
