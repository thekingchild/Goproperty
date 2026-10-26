<?php

declare(strict_types=1);

use App\Models\StaffStatus;
use App\Settings\SettingsStore;
use App\Theme\ThemeManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * P00-09a: draft, publish and restore for the theme, website and SEO
 * settings (PLATFORM_CONSOLE.md § 9.8).
 */

beforeEach(function () {
    $this->themes = app(ThemeManager::class);
    $this->staff = staffMember(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);
});

it('starts on the shipped defaults', function () {
    $theme = $this->themes->published();

    expect($theme->name())->toBe('GoProperty')
        ->and($theme->primary())->toBe('#1d4ed8')
        ->and($theme->fontFamily())->toBe('Inter')
        ->and($theme->get('site.hero_heading'))->toBe('Rent in.')
        ->and($theme->cssVariables())->toMatchArray([
            '--color-primary-700' => '#1d4ed8',
            '--color-brand-accent' => '#f59e0b',
            '--hero-deep' => '#0f1b44',
            '--hero-overlay-opacity' => '0.6',
            '--ink' => '#0f172a',
            '--gp-radius' => '14px',
        ])
        ->and($this->themes->hasDraft())->toBeFalse();
});

it('versions only theme, website and SEO keys', function () {
    expect($this->themes->keys())->toContain('theme.color_primary', 'site.faq', 'seo.title_pattern')
        ->not->toContain('custom_code.head', 'auth.turnstile_secret_key');
});

it('keeps a draft away from the published values', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E', 'site.hero_heading' => 'Rent sorted.'], $this->staff);

    expect($this->themes->hasDraft())->toBeTrue()
        ->and($this->themes->draftTheme()->primary())->toBe('#0f766e')
        ->and($this->themes->draftTheme()->get('site.hero_heading'))->toBe('Rent sorted.')
        ->and($this->themes->published()->primary())->toBe('#1d4ed8')
        ->and(app(SettingsStore::class)->get('site.hero_heading'))->toBe('Rent in.');
});

it('merges further edits into the same draft', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E']);
    $this->themes->saveDraft(['theme.radius' => 'sharp']);

    expect(DB::table('setting_versions')->where('status', 'draft')->count())->toBe(1)
        ->and($this->themes->draftTheme()->primary())->toBe('#0f766e')
        ->and($this->themes->draftTheme()->get('theme.radius'))->toBe('sharp');
});

it('validates draft values at once', function (string $key, mixed $value) {
    expect(fn () => $this->themes->saveDraft([$key => $value]))->toThrow(ValidationException::class)
        ->and($this->themes->hasDraft())->toBeFalse();
})->with([
    'not a colour' => ['theme.color_primary', 'blue'],
    'unknown font' => ['theme.font', 'comic-sans'],
    'markup in plain text' => ['site.hero_heading', '<b>Rent</b>'],
    'heading too long' => ['site.hero_heading', str_repeat('x', 41)],
    'two trust items' => ['site.hero_trust', ['One', 'Two']],
    'http social link' => ['site.social_links', ['x' => 'http://x.com/goproperty']],
    'unknown social network' => ['site.social_links', ['myspace' => 'https://myspace.com/x']],
    'pattern without page' => ['seo.title_pattern', '{site}'],
    'opacity over 100' => ['theme.hero_overlay_opacity', 140],
    'canonical over http' => ['seo.canonical_origin', 'http://goproperty.ng'],
]);

it('refuses keys that apply on save', function () {
    $this->themes->saveDraft(['custom_code.head' => '<script></script>']);
})->throws(InvalidArgumentException::class, 'custom_code.head is not drafted with the theme');

it('cleans HTML in FAQ answers and legal pages', function () {
    $values = $this->themes->saveDraft([
        'site.faq' => [['question' => 'Safe?', 'answer' => '<p onclick="steal()">Yes <script>alert(1)</script><a href="javascript:x()">link</a></p>']],
        'site.terms' => '<h2>Terms</h2><iframe src="https://evil.example"></iframe>',
    ]);

    expect($values['site.faq'][0]['answer'])->toBe('<p>Yes <a rel="noopener noreferrer">link</a></p>')
        ->and($values['site.terms'])->toBe('<h2>Terms</h2>');
});

it('publishes the draft as a numbered, audited version', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E', 'site.hero_heading' => 'Rent sorted.'], $this->staff);

    $version = $this->themes->publish($this->staff);

    expect($version)->toBe(1)
        ->and($this->themes->hasDraft())->toBeFalse()
        ->and($this->themes->published()->primary())->toBe('#0f766e')
        ->and(app(SettingsStore::class)->get('site.hero_heading'))->toBe('Rent sorted.')
        ->and(Activity::query()->where('log_name', 'settings')->pluck('properties')->pluck('key')->all())->toEqualCanonicalizing(['theme.color_primary', 'site.hero_heading'])
        ->and(Activity::query()->where('log_name', 'theme')->where('event', 'published')->sole()->causer?->is($this->staff))->toBeTrue()
        ->and(Activity::query()->where('log_name', 'theme')->where('event', 'draft_saved')->sole()->properties['changes'])->toHaveKeys(['theme.color_primary', 'site.hero_heading']);
});

it('archives the previous version and can restore it', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E']);
    $this->themes->publish($this->staff);
    $this->themes->saveDraft(['theme.color_primary' => '#B91C1C']);
    $this->themes->publish($this->staff);

    expect(collect($this->themes->versions())->pluck('status', 'version')->all())->toBe([2 => 'published', 1 => 'archived']);

    $this->themes->restore(1, $this->staff);

    expect($this->themes->draftTheme()->primary())->toBe('#0f766e')
        ->and($this->themes->published()->primary())->toBe('#b91c1c');
});

it('starts a draft from the shipped defaults', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E', 'theme.font' => 'manrope']);
    $this->themes->publish();

    $this->themes->resetDraftToDefaults();

    expect($this->themes->draftTheme()->primary())->toBe('#1d4ed8')
        ->and($this->themes->draftTheme()->fontAlias())->toBe('inter')
        ->and($this->themes->published()->fontAlias())->toBe('manrope');
});

it('discards a draft', function () {
    $this->themes->saveDraft(['theme.color_primary' => '#0F766E']);
    $this->themes->discardDraft();

    expect($this->themes->hasDraft())->toBeFalse()
        ->and(fn () => $this->themes->publish())->toThrow(RuntimeException::class, 'There is no draft to publish.');
});

it('refuses to restore a version that does not exist', function () {
    $this->themes->restore(9);
})->throws(InvalidArgumentException::class);
