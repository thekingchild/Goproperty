<?php

declare(strict_types=1);

namespace App\Installer;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Outgoing email over SMTP (INSTALLER.md § 5, step 5). Postmark, Mailgun
 * and SES all offer SMTP, so this covers every allowed provider without
 * extra packages; never the host's own sendmail (ARCHITECTURE.md § 12.1).
 */
final readonly class MailSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        #[SensitiveParameter] private string $password,
        public string $fromAddress,
    ) {
        if (preg_match('/^[A-Za-z0-9.\-]{1,253}$/D', $host) !== 1) {
            throw new InvalidArgumentException('The mail host must be a host name, such as smtp.postmarkapp.com.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The mail port must be a number from 1 to 65535.');
        }

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The from address must be an email address.');
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $username.$password) === 1) {
            throw new InvalidArgumentException('The mail username and password must not contain control characters.');
        }
    }

    public function password(): string
    {
        return $this->password;
    }

    /** Port 465 is SMTP over TLS; 587 and 25 upgrade with STARTTLS. */
    public function scheme(): string
    {
        return $this->port === 465 ? 'smtps' : 'smtp';
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => (string) $this->port, 'username' => $this->username, 'password' => '***', 'from' => $this->fromAddress];
    }
}
