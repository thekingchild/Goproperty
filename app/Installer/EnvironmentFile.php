<?php

declare(strict_types=1);

namespace App\Installer;

use App\Installer\Support\AtomicFile;
use Dotenv\Dotenv;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes `.env` for the installer (INSTALLER.md § 4–5): created from
 * `.env.example` when missing, mode 0600, values validated, quoted and
 * escaped, duplicates removed, and written atomically. Error messages name
 * the key, never the value, so passwords are never echoed.
 */
final class EnvironmentFile
{
    public function __construct(
        private readonly string $path,
        private readonly ?string $examplePath = null,
    ) {}

    public static function forApplication(): self
    {
        return new self(app()->environmentFilePath(), base_path('.env.example'));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** Creates `.env` from `.env.example` (or empty). True when it was created. */
    public function ensureExists(): bool
    {
        if ($this->exists()) {
            return false;
        }

        $example = $this->examplePath !== null && is_file($this->examplePath)
            ? (string) file_get_contents($this->examplePath)
            : '';

        AtomicFile::write($this->path, $example, 0600);

        return true;
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        try {
            $values = Dotenv::parse((string) file_get_contents($this->path));
        } catch (Throwable) {
            return null;
        }

        return $values[$key] ?? null;
    }

    /**
     * Sets keys, replacing the first existing line of each key in place,
     * removing later duplicates and appending keys that are new.
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $lines = [];

        foreach ($values as $key => $value) {
            $lines[$key] = $key.'='.self::encode($key, $value);
        }

        $this->ensureExists();
        $current = (string) file_get_contents($this->path);
        $output = [];
        $written = [];

        foreach (preg_split('/\r\n|\n|\r/', $current) ?: [] as $line) {
            $key = preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $match) === 1 ? $match[1] : null;

            if ($key === null || ! isset($lines[$key])) {
                $output[] = $line;
            } elseif (! isset($written[$key])) {
                $output[] = $lines[$key];
                $written[$key] = true;
            }
        }

        while ($output !== [] && end($output) === '') {
            array_pop($output);
        }

        foreach ($lines as $key => $line) {
            if (! isset($written[$key])) {
                $output[] = $line;
            }
        }

        AtomicFile::write($this->path, implode("\n", $output)."\n", 0600);
    }

    /**
     * Plain values stay bare; anything else is double-quoted with `\`, `"`
     * and `$` escaped, so `${...}` is never expanded. The line is parsed back
     * to prove it reads as the value written.
     */
    public static function encode(string $key, string|int|bool|null $value): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("{$key} is not a valid setting name.");
        }

        $value = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };

        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException("The value for {$key} must not contain line breaks or control characters.");
        }

        $encoded = preg_match('/^[A-Za-z0-9_.\/:@+,\-]*$/D', $value) === 1
            ? $value
            : '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';

        try {
            $parsed = Dotenv::parse("{$key}={$encoded}")[$key] ?? null;
        } catch (Throwable) {
            $parsed = null;
        }

        if ($parsed !== $value) {
            throw new RuntimeException("The value for {$key} cannot be written to .env safely.");
        }

        return $encoded;
    }
}
