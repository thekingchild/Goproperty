<?php

declare(strict_types=1);

use App\Models\StaffStatus;
use App\Models\User;
use App\Settings\SettingsStore;
use App\Theme\ThemeManager;

/*
 * P00-09b: search and sharing tags, robots.txt and the sitemap
 * (PLATFORM_CONSOLE.md § 9.4), and custom code on public pages only (§ 9.5).
 */

function publishTheme(array $changes): void
{
    $themes = app(ThemeManager::class);
    $themes->saveDraft($changes);
    $themes->publish();
}

it('prints the search and sharing tags', function () {
    publishTheme([
        'seo.description' => 'Rent collection for Nigerian agencies.',
        'seo.canonical_origin' => 'https://goproperty.ng',
        'seo.title_pattern' => '{page} | {site}',
    ]);

    $this->get('/')
        ->assertSee('<meta name="description" content="Rent collection for Nigerian agencies.">', false)
        ->assertSee('<link rel="canonical" href="https://goproperty.ng/">', false)
        ->assertSee('<meta property="og:url" content="https://goproperty.ng/">', false)
        ->assertSee('<meta property="og:site_name" content="GoProperty">', false)
        ->assertSee('<meta property="og:locale" content="en_NG">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<meta name="theme-color" content="#0f1b44">', false)
        ->assertSee('"@type":"SoftwareApplication"', false)
        ->assertSee('"offers":{"@type":"Offer","price":"10000","priceCurrency":"NGN"', false);

    $this->get('/terms')
        ->assertSee('<title>Terms of service | GoProperty</title>', false)
        ->assertSee('<link rel="canonical" href="https://goproperty.ng/terms">', false)
        ->assertDontSee('application/ld+json', false);
});

it('uses the SEO title when one is set', function () {
    publishTheme(['seo.title' => 'GoProperty: rent in, receipts out']);

    $this->get('/')->assertSee('<title>GoProperty: rent in, receipts out</title>', false);
});

it('keeps pages out of search engines unless indexing is on', function () {
    // Testing is not production, so indexing is off by default.
    $this->get('/')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n");
    $this->get('/sitemap.xml')->assertNotFound();

    publishTheme(['seo.indexing_enabled' => true]);

    $this->get('/')->assertSee('<meta name="robots" content="index, follow">', false);
    $this->get('/sitemap.xml')->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<loc>'.url('/').'/</loc>', false)
        ->assertSee('<loc>'.url('/privacy').'</loc>', false)
        ->assertSee('<loc>'.url('/terms').'</loc>', false);
});

it('indexes production by default and never names the console in robots.txt', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    $robots = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

    expect($robots)
        ->toContain("Disallow: /login\n", "Disallow: /o/\n", "Disallow: /portal\n", "Disallow: /owner\n", "Disallow: /pay/\n", "Disallow: /verify/\n")
        ->toContain('Sitemap: '.url('/sitemap.xml'))
        ->not->toContain((string) config('app.admin_path'))
        ->not->toContain("Disallow: /\n");
});

it('marks sign-in and app pages noindex', function () {
    $this->get('/login')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    $this->actingAs(User::factory()->create())->get('/portal')->assertSee('<meta name="robots" content="noindex, nofollow" />', false);
});

describe('custom code', function () {
    beforeEach(function () {
        $settings = app(SettingsStore::class);
        $settings->set('custom_code.head', '<script src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>');
        $settings->set('custom_code.body_start', '<noscript>body-start-marker</noscript>');
        $settings->set('custom_code.body_end', '<script>window.bodyEndMarker = 1</script>');
        $settings->set('custom_code.css', '.hero__title { letter-spacing: -0.05em }');
    });

    it('prints it in its places on public pages', function () {
        $page = (string) $this->get('/')->assertOk()->getContent();

        expect($page)
            ->toMatch('#<style data-custom-css>[^<]*</style>\s*<script src="https://www.googletagmanager.com/gtag/js\?id=G-TEST"></script>.*</head>#s')
            ->toMatch('#<body >\s*<noscript>body-start-marker</noscript>#')
            ->toMatch('#<script type="application/json" data-page>[^<]*</script>\s*<script>window.bodyEndMarker = 1</script>#')
            ->toContain('<style data-custom-css>.hero__title { letter-spacing: -0.05em }</style>');

        $this->get('/privacy')->assertSee('body-start-marker', false);
    });

    it('leaves it out in safe mode', function () {
        $this->get('/?safe=1')->assertOk()
            ->assertDontSee('G-TEST', false)
            ->assertDontSee('body-start-marker', false)
            ->assertDontSee('bodyEndMarker', false)
            ->assertDontSee('data-custom-css', false);
    });

    it('never prints it for a signed-in user or on sign-in and app pages', function () {
        $user = User::factory()->create();

        $this->get('/login')->assertDontSee('G-TEST', false)->assertDontSee('body-start-marker', false);
        $this->get('/register')->assertDontSee('G-TEST', false);

        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('G-TEST', false)->assertDontSee('bodyEndMarker', false);
        $this->actingAs($user)->get('/portal')->assertDontSee('G-TEST', false);
    });

    it('never prints it in the console preview', function () {
        $staff = staffMember(['name' => 'Ada', 'email' => 'ada@goproperty.ng', 'password' => 'x-long-password-1', 'status' => StaffStatus::Active]);

        $this->actingAs($staff, 'staff')->get('/'.config('app.admin_path').'/theme-preview/landing')
            ->assertOk()
            ->assertDontSee('G-TEST', false)
            ->assertDontSee('body-start-marker', false);
    });
});

it('allows scripts from the listed hosts only', function () {
    app(SettingsStore::class)->set('custom_code.allowed_hosts', ['www.googletagmanager.com', 'connect.facebook.net']);

    $csp = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("script-src 'self' https://www.googletagmanager.com https://connect.facebook.net 'unsafe-inline'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'self'")
        ->not->toContain('google-analytics.com');
});
