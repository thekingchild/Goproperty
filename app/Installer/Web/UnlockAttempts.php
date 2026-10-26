<?php

declare(strict_types=1);

namespace App\Installer\Web;

use App\Installer\InstallationMarkers;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * 5 unlock attempts per IP address per 15 minutes (INSTALLER.md § 4),
 * counted in a file under an exclusive `flock`: the database and cache do
 * not exist yet, and two requests at once must not both slip through.
 * Addresses are stored hashed.
 */
final class UnlockAttempts
{
    public function __construct(
        private readonly InstallationMarkers $markers,
        private readonly Config $config,
    ) {}

    /**
     * Counts one attempt from this address. False when it is over the
     * limit (the attempt is then not counted again).
     *
     * @throws RuntimeException when the counter file cannot be written
     */
    public function attempt(string $ip): bool
    {
        return $this->locked(function (array $attempts) use ($ip): array {
            $key = self::key($ip);
            $mine = $attempts[$key] ?? [];

            if (count($mine) >= $this->limit()) {
                return [$attempts, false];
            }

            $attempts[$key] = [...$mine, now()->getTimestamp()];

            return [$attempts, true];
        });
    }

    /** Forgets this address's attempts after a successful unlock. */
    public function clear(string $ip): void
    {
        $this->locked(function (array $attempts) use ($ip): array {
            unset($attempts[self::key($ip)]);

            return [$attempts, true];
        });
    }

    public function decayMinutes(): int
    {
        return $this->setting('installer.unlock_decay_minutes', 15);
    }

    private function limit(): int
    {
        return $this->setting('installer.unlock_attempts', 5);
    }

    /**
     * @param  callable(array<string, list<int>>): array{0: array<string, list<int>>, 1: bool}  $change
     */
    private function locked(callable $change): bool
    {
        $path = $this->markers->path('attempts');
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('The installer cannot write in storage/app/private.');
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('The installer cannot write in storage/app/private.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('The installer cannot lock its attempt counter.');
            }

            @chmod($path, 0600);
            [$attempts, $result] = $change($this->prune(json_decode((string) stream_get_contents($handle), true)));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($attempts));
            fflush($handle);
            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, list<int>> attempts inside the window */
    private function prune(mixed $data): array
    {
        $since = now()->getTimestamp() - $this->decayMinutes() * 60;
        $attempts = [];

        foreach (is_array($data) ? $data : [] as $key => $times) {
            $recent = array_values(array_filter(is_array($times) ? $times : [], fn (mixed $time): bool => is_int($time) && $time > $since));

            if (is_string($key) && $recent !== []) {
                $attempts[$key] = $recent;
            }
        }

        return $attempts;
    }

    private static function key(string $ip): string
    {
        return hash('sha256', 'install-unlock|'.$ip);
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
