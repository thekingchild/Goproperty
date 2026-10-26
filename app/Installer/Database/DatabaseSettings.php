<?php

declare(strict_types=1);

namespace App\Installer\Database;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * The database the owner enters in the installer (INSTALLER.md § 5, step 3).
 * The password never appears in messages, logs or var_dump output.
 */
final readonly class DatabaseSettings
{
    public const array DRIVERS = ['mysql', 'mariadb'];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter] private string $password,
    ) {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException('Choose MySQL or MariaDB.');
        }

        if (preg_match('/^[A-Za-z0-9.\-]{1,253}$/D', $host) !== 1 && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('The host must be a host name or an IP address, such as localhost.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The port must be a number from 1 to 65535.');
        }

        if (preg_match('/^[A-Za-z0-9_$\-]{1,64}$/D', $database) !== 1) {
            throw new InvalidArgumentException('The database name may use letters, numbers, "_", "-" and "$" (up to 64).');
        }

        if (preg_match('/^[A-Za-z0-9_.@$\-]{1,80}$/D', $username) !== 1) {
            throw new InvalidArgumentException('The username may use letters, numbers, "_", ".", "@", "-" and "$" (up to 80).');
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $password) === 1) {
            throw new InvalidArgumentException('The password must not contain control characters.');
        }
    }

    /**
     * @param  array<string, mixed>  $connection  a `database.connections.*` entry
     */
    public static function fromConnection(array $connection): self
    {
        $driver = is_string($connection['driver'] ?? null) ? $connection['driver'] : 'mysql';
        $port = $connection['port'] ?? 3306;

        return new self(
            $driver,
            is_string($connection['host'] ?? null) ? $connection['host'] : '127.0.0.1',
            is_numeric($port) ? (int) $port : 3306,
            is_string($connection['database'] ?? null) ? $connection['database'] : '',
            is_string($connection['username'] ?? null) ? $connection['username'] : '',
            is_string($connection['password'] ?? null) ? $connection['password'] : '',
        );
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * A copy of the default connection pointed at these settings, with a short
     * connect timeout.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function connectionConfig(array $base, int $timeoutSeconds): array
    {
        $options = is_array($base['options'] ?? null) ? $base['options'] : [];
        $options[\PDO::ATTR_TIMEOUT] = $timeoutSeconds;

        return array_merge($base, [
            'driver' => $this->driver,
            'url' => null,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'unix_socket' => '',
            'options' => $options,
        ]);
    }

    /**
     * Keyed hash of every setting, stored in the checkpoint to notice settings
     * that change mid-install (INSTALLER.md § 6).
     */
    public function fingerprint(#[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', implode("\n", [
            $this->driver, $this->host, (string) $this->port, $this->database, $this->username, $this->password,
        ]), 'install-fingerprint|'.$key);
    }

    /**
     * Removes these settings' secrets from a driver message.
     */
    public function scrub(string $message): string
    {
        $secrets = array_filter([$this->password, $this->username, $this->host], fn (string $value): bool => $value !== '');

        return str_replace($secrets, '***', $message);
    }

    /** @return array<string, string|int> */
    public function __debugInfo(): array
    {
        return ['driver' => $this->driver, 'host' => $this->host, 'port' => $this->port, 'database' => $this->database, 'username' => $this->username, 'password' => '***'];
    }
}
