<?php

declare(strict_types=1);

use App\Models\StaffStatus;
use App\Theme\ThemeManager;

/*
 * P00-09b: the landing page is the approved design
 * (docs/design/templates/landing.html) built from the published theme
 * settings and the plan catalogue (UX_DESIGN.md § 4, PLATFORM_CONSOLE.md § 9.3).
 */

/**
 * Tag and class of every element under <main>, in document order: the
 * page's structure without its text.
 *
 * @return list<string>
 */
function mainStructure(string $html): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR);
    $main = $document->getElementsByTagName('main')->item(0);
    expect($main)->not->toBeNull();

    $structure = [];

    foreach ((new DOMXPath($document))->query('.//*', $main) ?: [] as $element) {
        if (! $element instanceof DOMElement) {
            continue;
        }

        // State the page script sets either way (the matching plan).
        $classes = array_diff(preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], ['', 'is-match']);
        $structure[] = $element->tagName.($classes === [] ? '' : '.'.implode('.', $classes)).($element->getAttribute('id') !== '' ? '#'.$element->getAttribute('id') : '');
    }

    return $structure;
}

it('matches the approved template, section by section', function () {
    $template = (string) file_get_contents(base_path('docs/design/templates/landing.html'));

    $page = $this->get('/')->assertOk()->getContent();

    expect(mainStructure((string) $page))->toBe(mainStructure($template));
});

it('shows the default theme content and prices', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('<title>GoProperty — Property management for Nigeria</title>', false)
        ->assertSeeInOrder(['Built for Nigerian property managers', 'Rent in.', 'Receipts out.', '14-day free trial', 'No card required', 'Cancel anytime'])
        ->assertSee('GoProperty keeps your buildings, tenants, leases and utility bills in one place.')
        ->assertSeeInOrder(['Starter', '₦10,000', 'Growth', '₦35,000', 'Scale', '₦85,000', 'Enterprise', '₦250,000'])
        ->assertSee('data-price-year="₦350,000"', false)
        ->assertSee('Starter · ₦800 per unit a month · plus 7.5% VAT')
        ->assertSee('2 months free')
        ->assertSee('Remove &quot;Generated with GoProperty&quot;', false)
        ->assertSee('Does GoProperty hold my tenants&#039; money?', false)
        ->assertSee('"brand":"GoProperty"', false)
        ->assertSee(route('register'), false)
        ->assertSee(route('privacy'), false);
});

it('shows published website settings, never a draft', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft([
        'theme.name' => 'Ile Homes',
        'site.hero_heading' => 'Pay day.',
        'site.hero_subheading' => 'Sorted.',
        'site.hero_trust' => ['Free for a month', 'No card', 'Leave anytime'],
        'site.faq' => [['question' => 'Who are you?', 'answer' => '<p>A property manager\'s <strong>friend</strong>.</p><script>alert(1)</script>']],
        'site.footer_text' => 'Rent made calm.',
        'site.social_links' => ['linkedin' => 'https://www.linkedin.com/company/ile'],
    ]);

    $this->get('/')->assertSee('Rent in.')->assertDontSee('Pay day.')->assertDontSee('Ile Homes');

    $themes->publish();

    $this->get('/')
        ->assertSee('Ile Homes — Property management for Nigeria', false)
        ->assertSeeInOrder(['Pay day.', 'Sorted.', 'Free for a month'])
        ->assertSee('<strong>friend</strong>', false)
        ->assertDontSee('alert(1)', false)
        ->assertSee('Rent made calm.')
        ->assertSee('href="https://www.linkedin.com/company/ile"', false)
        ->assertSee('Ile Homes on LinkedIn')
        ->assertDontSee('on Instagram')
        ->assertDontSee('Rent in.');
});

it('shows the announcement ribbon when one is set', function () {
    $this->get('/')->assertDontSee('data-announcement', false);

    $themes = app(ThemeManager::class);
    $themes->saveDraft(['site.announcement' => 'New: WhatsApp reminders', 'site.announcement_link' => 'https://goproperty.ng/news']);
    $themes->publish();

    $this->get('/')
        ->assertSee('data-announcement=', false)
        ->assertSee('<a href="https://goproperty.ng/news">New: WhatsApp reminders', false);
});

it('shows the hero and brand from the theme colours', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['theme.color_primary' => '#0F766E', 'theme.color_accent' => '#DB2777']);
    $themes->publish();

    $this->get('/')
        ->assertSee('--color-primary-700:#0f766e', false)
        ->assertSee('--color-brand-accent:#db2777', false);
});

it('renders the privacy policy and terms from the settings', function () {
    $themes = app(ThemeManager::class);
    $themes->saveDraft(['site.terms' => '<h2>Our terms</h2><p>Be <a href="https://example.com" onclick="x()">kind</a>.</p>']);
    $themes->publish();

    $this->get('/privacy')->assertOk()
        ->assertSee('<title>Privacy policy · GoProperty</title>', false)
        ->assertSee('<h2>Privacy policy</h2>', false);

    $this->get('/terms')->assertOk()
        ->assertSee('<h2>Our terms</h2>', false)
        ->assertSee('rel="noopener noreferrer"', false)
        ->assertDontSee('onclick', false);
});

it('previews the landing page with the draft theme, to staff only', function () {
    $staff = staffMember(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);
    app(ThemeManager::class)->saveDraft(['site.hero_heading' => 'Pay day.', 'theme.color_primary' => '#B91C1C'], $staff);
    $preview = '/'.config('app.admin_path').'/theme-preview/landing';

    $this->get($preview)->assertRedirect('/'.config('app.admin_path').'/login');

    $this->actingAs($staff, 'staff')->get($preview)
        ->assertOk()
        ->assertSee('Pay day.')
        ->assertSee('--color-primary-700:#b91c1c', false)
        ->assertSee('Preview of the draft theme')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->get('/')->assertSee('Rent in.')->assertDontSee('Pay day.')->assertDontSee('#b91c1c', false)->assertDontSee('preview-banner', false);
});
