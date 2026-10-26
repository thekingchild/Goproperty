<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Support\LocalHost;
use InvalidArgumentException;

/**
 * Site address and environment (INSTALLER.md § 5, step 3): an `https://`
 * origin with no path. A local test copy (localhost, *.test) may use http.
 */
final readonly class SiteSettings
{
    public const array ENVIRONMENTS = ['production', 'staging'];

    public string $url;

    public function __construct(string $url, public string $environment)
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('The site address must be just the origin, such as https://goproperty.ng (no path).');
        }

        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && LocalHost::is($parts['host']))) {
            throw new InvalidArgumentException('The site address must start with https:// (http:// is allowed only for localhost or *.test).');
        }

        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('The environment must be production or staging.');
        }

        $this->url = $url;
    }
}
