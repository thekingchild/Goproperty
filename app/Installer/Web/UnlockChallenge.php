<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Installer\InstallationMarkers;
use App\Installer\Support\AtomicFile;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Unlock method B (INSTALLER.md § 4): the owner proves control of the
 * hosting account by creating `storage/app/install-unlock.txt` with a phrase
 * shown on screen. Only the phrase's hash is stored, so each view of the
 * first screen issues a new phrase; the most recent ones all stay valid for
 * 30 minutes, so reloading the page never breaks a file already created.
 *
 * The phrase is not a secret (anyone who opens /install sees one): the proof
 * is that the file exists outside the web root, which only the hosting
 * account can arrange.
 */
final class UnlockChallenge
{
    public const string PREFIX = 'GP-UNLOCK';

    /** Phrases kept at once; older ones drop off first. */
    public const int KEEP = 20;

    /** The file is a short phrase; anything bigger is not ours. */
    private const int MAX_FILE_BYTES = 512;

    // No 0/O, 1/I: the owner copies the phrase by hand.
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly Config $config,
    ) {}

    /** A new phrase, such as "GP-UNLOCK 7Q4M-K2XD-PL9A". */
    public function issue(): string
    {
        $groups = [];

        for ($group = 0; $group < 3; $group++) {
            $chars = '';

            for ($i = 0; $i < 4; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $chars;
        }

        $phrase = self::PREFIX.' '.implode('-', $groups);
        $entries = $this->entries();
        $entries[] = ['hash' => self::hash($phrase), 'expires_at' => now()->addMinutes($this->minutes())->getTimestamp()];

        $this->store(array_slice($entries, -self::KEEP));

        return $phrase;
    }

    public function minutes(): int
    {
        $minutes = $this->config->get('installer.challenge_minutes', 30);

        return is_numeric($minutes) ? max(1, (int) $minutes) : 30;
    }

    public function filePath(): string
    {
        return $this->markers->path('unlock_file');
    }

    public function fileExists(): bool
    {
        return is_file($this->filePath());
    }

    /**
     * True when the unlock file holds a phrase issued in the last 30
     * minutes. On success the file and every phrase are deleted, so each
     * file unlocks once.
     */
    public function verifyFile(): bool
    {
        $path = $this->filePath();

        if (is_link($path) || ! is_file($path) || (int) @filesize($path) > self::MAX_FILE_BYTES) {
            return false;
        }

        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            return false;
        }

        $hash = self::hash($contents);

        foreach ($this->entries() as $entry) {
            if (hash_equals($entry['hash'], $hash)) {
                @unlink($path);
                $this->clear();

                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        $path = $this->markers->path('unlock_challenge');

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * File Manager editors add a byte-order mark, Windows line endings or a
     * trailing new line, and people retype in lower case: none of that
     * should matter.
     */
    public static function normalize(string $phrase): string
    {
        $phrase = (string) preg_replace('/^\xEF\xBB\xBF/', '', $phrase);

        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $phrase)));
    }

    private static function hash(string $phrase): string
    {
        return hash('sha256', self::normalize($phrase));
    }

    /** @return list<array{hash: string, expires_at: int}> unexpired phrases, oldest first */
    private function entries(): array
    {
        $path = $this->markers->path('unlock_challenge');
        $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
        $now = now()->getTimestamp();
        $entries = [];

        foreach (is_array($data) ? $data : [] as $entry) {
            if (is_array($entry) && is_string($entry['hash'] ?? null) && is_int($entry['expires_at'] ?? null) && $entry['expires_at'] > $now) {
                $entries[] = ['hash' => $entry['hash'], 'expires_at' => $entry['expires_at']];
            }
        }

        return $entries;
    }

    /** @param  list<array{hash: string, expires_at: int}>  $entries */
    private function store(array $entries): void
    {
        AtomicFile::write($this->markers->path('unlock_challenge'), (string) json_encode($entries)."\n", 0600);
    }
}
