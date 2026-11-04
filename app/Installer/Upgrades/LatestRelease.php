<?php

declare(strict_types=1);

namespace App\Installer\Upgrades;

use App\Installer\AppVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The newest published release of GoProperty on GitHub, for Console →
 * Operations → Version (INSTALLER.md § 10, step 2). Read-only: it asks the
 * public releases API (`/repos/{owner}/{name}/releases/latest`, which skips
 * drafts and pre-releases) and never downloads anything, so a stolen
 * console session cannot replace the code.
 *
 * Asked only in production and staging (the list is written out here, as
 * for every outside call, AGENT.md § 7), with 3 s to connect and 5 s in all,
 * and remembered in the cache: 6 hours after an answer, 15 minutes after a
 * failure, so an unreachable GitHub never slows the page twice. A failure
 * is shown in words, never thrown.
 */
final readonly class LatestRelease
{
    public const array LIVE_ENVIRONMENTS = ['production', 'staging'];

    private const string CACHE_PREFIX = 'upgrades:latest-release:';

    public function __construct(
        private Application $app,
        private Config $config,
        private Cache $cache,
    ) {}

    public function check(): ReleaseCheck
    {
        $repository = $this->repository();

        if ($repository === null) {
            return new ReleaseCheck(ReleaseCheckStatus::NotChecked, 'No GitHub repository is set (GOPROPERTY_RELEASES_REPOSITORY), so the latest release is not checked.');
        }

        if (! in_array($this->app->environment(), self::LIVE_ENVIRONMENTS, true)) {
            return new ReleaseCheck(ReleaseCheckStatus::NotChecked, 'GitHub is asked for the latest release only in production and staging.');
        }

        $cached = $this->cache->get(self::CACHE_PREFIX.$repository);
        $known = is_array($cached) ? ReleaseCheck::fromArray($cached) : null;

        if ($known !== null) {
            return $known;
        }

        $check = $this->ask($repository);
        $minutes = $check->status === ReleaseCheckStatus::Failed ? 'failure_cache_minutes' : 'cache_minutes';
        $this->cache->put(self::CACHE_PREFIX.$repository, $check->toArray(), now()->addMinutes($this->minutes($minutes)));

        return $check;
    }

    /** Asks again on the next check (Console → Version → Check again). */
    public function forget(): void
    {
        $repository = $this->repository();

        if ($repository !== null) {
            $this->cache->forget(self::CACHE_PREFIX.$repository);
        }
    }

    /** GitHub is asked here: a repository is set and this is production or staging. */
    public function asks(): bool
    {
        return $this->repository() !== null && in_array($this->app->environment(), self::LIVE_ENVIRONMENTS, true);
    }

    public function repository(): ?string
    {
        $repository = $this->config->get('installer.releases.repository');

        return is_string($repository) && preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repository) === 1 ? $repository : null;
    }

    private function ask(string $repository): ReleaseCheck
    {
        $now = CarbonImmutable::now();

        try {
            $response = Http::connectTimeout(3)->timeout(5)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->withUserAgent('GoProperty/'.AppVersion::current())
                ->get("https://api.github.com/repos/{$repository}/releases/latest");
        } catch (ConnectionException) {
            return new ReleaseCheck(ReleaseCheckStatus::Failed, 'GitHub could not be reached. The page asks again in 15 minutes.', checkedAt: $now);
        }

        if ($response->status() === 404) {
            return new ReleaseCheck(ReleaseCheckStatus::NoRelease, "{$repository} has no published release yet (or the repository is private).", checkedAt: $now);
        }

        if (! $response->successful()) {
            return new ReleaseCheck(ReleaseCheckStatus::Failed, 'GitHub answered HTTP '.$response->status().'. The page asks again in 15 minutes.', checkedAt: $now);
        }

        $tag = $response->json('tag_name');
        $url = $response->json('html_url');
        $published = $response->json('published_at');

        if (! is_string($tag) || ! AppVersion::isRelease($tag)) {
            return new ReleaseCheck(ReleaseCheckStatus::Failed, 'GitHub\'s latest release has no version number GoProperty recognises.', checkedAt: $now);
        }

        try {
            $publishedAt = is_string($published) ? CarbonImmutable::parse($published) : null;
        } catch (Throwable) {
            $publishedAt = null;
        }

        return new ReleaseCheck(
            ReleaseCheckStatus::Found,
            'Latest release on GitHub.',
            ltrim($tag, 'v'),
            // Only a link to the release page on github.com is ever shown.
            is_string($url) && str_starts_with($url, 'https://github.com/') ? $url : null,
            $publishedAt,
            $now,
        );
    }

    private function minutes(string $key): int
    {
        $value = $this->config->get('installer.releases.'.$key);

        return is_numeric($value) ? max(1, (int) $value) : 15;
    }
}
