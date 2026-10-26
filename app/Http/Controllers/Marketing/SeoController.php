<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketing;

use App\Marketing\PublicSite;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml (PLATFORM_CONSOLE.md § 9.4). The console path
 * is never mentioned; with indexing off robots.txt disallows everything and
 * the sitemap is not found.
 */
final class SeoController
{
    /** Paths crawlers are kept out of (signed-in and money pages). */
    public const array DISALLOWED = ['/login', '/register', '/o/', '/portal', '/owner', '/pay/', '/verify/', '/install', '/dashboard', '/settings'];

    /** Marketing pages listed in the sitemap, by route name. */
    public const array PAGES = ['home', 'privacy', 'terms'];

    public function robots(PublicSite $site): Response
    {
        $lines = ['User-agent: *'];

        if ($site->indexing()) {
            foreach (self::DISALLOWED as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.$site->canonical('sitemap.xml');
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(PublicSite $site, Cache $cache): Response
    {
        abort_unless($site->indexing(), 404);

        $xml = $cache->remember('seo:sitemap:'.md5($site->origin()), 600, function () use ($site): string {
            $urls = '';

            foreach (self::PAGES as $name) {
                $urls .= '<url><loc>'.e($site->canonical(route($name, absolute: false))).'</loc></url>';
            }

            return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>'."\n";
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
