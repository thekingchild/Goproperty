<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Support\AtomicFile;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Unlock method A (INSTALLER.md § 4): a 40-character token printed once over
 * SSH. Only its SHA-256 is stored (mode 0600); the token is long and random,
 * so a plain hash is enough. Issuing a new token replaces the old one.
 */
final class SetupToken
{
    public const int LENGTH = 40;

    public function __construct(private readonly InstallationMarkers $markers) {}

    public function issue(): string
    {
        $token = Str::random(self::LENGTH);

        AtomicFile::write($this->path(), (string) json_encode([
            'hash' => hash('sha256', $token),
            'created_at' => now()->toIso8601String(),
        ])."\n", 0600);

        return $token;
    }

    public function verify(#[SensitiveParameter] string $token): bool
    {
        $data = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;
        $hash = is_array($data) ? ($data['hash'] ?? null) : null;

        return is_string($hash) && strlen($token) === self::LENGTH && hash_equals($hash, hash('sha256', $token));
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    private function path(): string
    {
        return $this->markers->path('setup_token');
    }
}
