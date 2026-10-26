<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Installer\InstallationMarkers;
use App\Installer\Support\AtomicFile;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The installer grant (INSTALLER.md § 4): proof that this browser session
 * unlocked the installer. The session holds a random token; the grant file
 * holds only its hash, so there is one grant at a time and a new unlock
 * ends any other session's access.
 *
 * - **Session-bound:** only the session holding the token passes.
 * - **Rotated on first use:** the first request after the unlock swaps the
 *   token and the session ID, so whatever saw the unlock response is useless.
 * - **60 minutes from the last completed step,** renewed by every step, so
 *   a slow 2FA enrolment never runs it out. When it does expire the owner
 *   unlocks again in the browser.
 */
final class InstallerGrant
{
    public const string SESSION_KEY = 'installer.grant';

    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly Config $config,
    ) {}

    public function issue(Session $session): void
    {
        $token = Str::random(40);

        $this->store(['hash' => self::hash($token), 'rotated' => false, 'renewed_at' => now()->getTimestamp()]);
        $session->put(self::SESSION_KEY, $token);
    }

    public function check(Session $session): GrantStatus
    {
        $grant = $this->read();
        $token = $session->get(self::SESSION_KEY);

        if ($grant === null || ! is_string($token) || ! hash_equals($grant['hash'], self::hash($token))) {
            return GrantStatus::Missing;
        }

        if (now()->getTimestamp() - $grant['renewed_at'] > $this->minutes() * 60) {
            return GrantStatus::Expired;
        }

        if (! $grant['rotated']) {
            $token = Str::random(40);
            $this->store(['hash' => self::hash($token), 'rotated' => true, 'renewed_at' => $grant['renewed_at']]);
            $session->put(self::SESSION_KEY, $token);
            $session->migrate(true);
        }

        return GrantStatus::Valid;
    }

    /** Called after every completed step. */
    public function renew(): void
    {
        $grant = $this->read();

        if ($grant !== null) {
            $this->store(['hash' => $grant['hash'], 'rotated' => $grant['rotated'], 'renewed_at' => now()->getTimestamp()]);
        }
    }

    public function minutes(): int
    {
        $minutes = $this->config->get('installer.grant_minutes', 60);

        return is_numeric($minutes) ? max(1, (int) $minutes) : 60;
    }

    /** @return array{hash: string, rotated: bool, renewed_at: int}|null */
    private function read(): ?array
    {
        $path = $this->markers->path('grant');
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

        if (! is_array($data) || ! is_string($data['hash'] ?? null) || ! is_bool($data['rotated'] ?? null) || ! is_int($data['renewed_at'] ?? null)) {
            return null;
        }

        return ['hash' => $data['hash'], 'rotated' => $data['rotated'], 'renewed_at' => $data['renewed_at']];
    }

    /** @param  array{hash: string, rotated: bool, renewed_at: int}  $grant */
    private function store(array $grant): void
    {
        AtomicFile::write($this->markers->path('grant'), (string) json_encode($grant)."\n", 0600);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
