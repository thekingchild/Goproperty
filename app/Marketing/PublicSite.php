<?php

declare(strict_types=1);

namespace App\Marketing;

use App\Settings\SettingsStore;
use App\Theme\BrandAssets;
use App\Theme\Theme;
use App\Theme\ThemeContext;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * What the public marketing pages print from the settings
 * (PLATFORM_CONSOLE.md § 9.4–9.5): titles, canonical links, indexing,
 * structured data and the owner's custom code.
 */
final readonly class PublicSite
{
    public const array CUSTOM_CODE_SLOTS = ['head', 'body_start', 'body_end', 'css'];

    public function __construct(
        private ThemeContext $themes,
        private SettingsStore $settings,
        private PlanCatalogue $plans,
        private Config $config,
        private Auth $auth,
        private Request $request,
        private BrandAssets $assets,
    ) {}

    public function theme(): Theme
    {
        return $this->themes->current();
    }

    /** "GoProperty — Property management for Nigeria", or the page in the title pattern. */
    public function title(?string $page = null): string
    {
        $theme = $this->theme();
        $site = $theme->string('seo.title', $theme->name().' — '.$theme->string('theme.tagline'));

        if ($page === null || $page === '') {
            return $site;
        }

        return strtr($theme->string('seo.title_pattern', '{page} · {site}'), ['{page}' => $page, '{site}' => $theme->name()]);
    }

    public function description(): ?string
    {
        $description = $this->theme()->string('seo.description');

        return $description === '' ? null : $description;
    }

    /** seo.canonical_origin, else APP_URL; no trailing slash. */
    public function origin(): string
    {
        $origin = $this->theme()->string('seo.canonical_origin');

        if ($origin === '') {
            $url = $this->config->get('app.url');
            $origin = is_string($url) ? $url : '';
        }

        return rtrim($origin, '/');
    }

    public function canonical(?string $path = null): string
    {
        $path = '/'.ltrim($path ?? $this->request->path(), '/');

        return $this->origin().($path === '/' ? '/' : $path);
    }

    /** Off unless switched on; when unset, on in production only. */
    public function indexing(): bool
    {
        $enabled = $this->theme()->get('seo.indexing_enabled');

        return is_bool($enabled) ? $enabled : $this->config->get('app.env') === 'production';
    }

    /** True inside the console's draft preview. */
    public function isPreview(): bool
    {
        return $this->themes->isPreview();
    }

    public function robots(): string
    {
        return $this->indexing() && ! $this->isPreview() ? 'index, follow' : 'noindex, nofollow';
    }

    /** The uploaded share image (no image yet: no og:image). */
    public function shareImage(): ?string
    {
        return $this->assets->url($this->theme(), 'share-image');
    }

    /** The uploaded favicon, else the icon, else the generated mark. */
    public function favicon(): string
    {
        return $this->assets->faviconUrl($this->theme());
    }

    /**
     * JSON-LD: Organization and SoftwareApplication with the Starter price.
     *
     * @return array<string, mixed>
     */
    public function structuredData(): array
    {
        $theme = $this->theme();
        $starter = $this->plans->starter();

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    'name' => $theme->name(),
                    'url' => $this->origin().'/',
                    'logo' => $this->assets->url($theme, 'logo-light') ?? route('brand.asset', 'logo-light'),
                ],
                [
                    '@type' => 'SoftwareApplication',
                    'name' => $theme->name(),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'offers' => [
                        '@type' => 'Offer',
                        'price' => (string) intdiv($starter->base->kobo, 100),
                        'priceCurrency' => 'NGN',
                        'description' => sprintf('%s plan, per month, up to %d units', $starter->name, $starter->includedUnits),
                    ],
                ],
            ],
        ];
    }

    /**
     * The owner's custom code (§ 9.5), or nothing: never for a signed-in
     * user, in the console preview, or with ?safe=1.
     *
     * @return array<string, string>
     */
    public function customCode(): array
    {
        if ($this->request->query('safe') === '1' || $this->isPreview() || $this->auth->guard('web')->check()) {
            return [];
        }

        $code = [];

        foreach (self::CUSTOM_CODE_SLOTS as $slot) {
            $value = $this->settings->get('custom_code.'.$slot);

            if (is_string($value) && trim($value) !== '') {
                $code[$slot] = $value;
            }
        }

        return $code;
    }

    /** @return list<string> */
    public function allowedScriptHosts(): array
    {
        $hosts = $this->settings->get('custom_code.allowed_hosts');

        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [];
    }

    /**
     * Footer social links that are set, in a fixed order.
     *
     * @return array<string, string>
     */
    public function socialLinks(): array
    {
        $links = $this->theme()->get('site.social_links');
        $order = ['x', 'linkedin', 'instagram', 'facebook', 'youtube', 'whatsapp'];
        $shown = [];

        foreach ($order as $network) {
            $url = is_array($links) ? ($links[$network] ?? null) : null;

            if (is_string($url) && $url !== '') {
                $shown[$network] = $url;
            }
        }

        return $shown;
    }
}
