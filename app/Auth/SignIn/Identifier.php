<?php

declare(strict_types=1);

namespace App\Auth\SignIn;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;

/**
 * What was typed in "Email or phone number" (UX_DESIGN.md § 3.1): an email
 * (lower-cased) or a phone number (normalised to +234…). Anything else is
 * kept as typed so it still counts towards lockouts.
 */
final readonly class Identifier
{
    private function __construct(
        public string $value,
        public ?string $email,
        public ?string $phone,
    ) {}

    public static function from(mixed $input): self
    {
        $typed = is_string($input) ? trim($input) : '';

        if (str_contains($typed, '@')) {
            $email = Str::lower($typed);

            return new self($email, $email, null);
        }

        $phone = PhoneNumber::normalize($typed);

        return $phone !== null ? new self($phone, null, $phone) : new self(Str::lower($typed), null, null);
    }

    /**
     * The same identifier for counting failures, keyed by the account when
     * it names one: typing the phone number after failing with the email
     * does not dodge a pause.
     */
    public function forLimits(): self
    {
        $id = match (true) {
            $this->email !== null => User::query()->where('email', $this->email)->value('id'),
            $this->phone !== null => User::query()->where('phone', $this->phone)->value('id'),
            default => null,
        };

        return is_int($id) ? new self('account:'.$id, $this->email, $this->phone) : $this;
    }

    /** The account forLimits() found, if any. */
    public function accountId(): ?int
    {
        return str_starts_with($this->value, 'account:') ? (int) substr($this->value, 8) : null;
    }

    /** For rate-limiter keys: never the identifier itself. */
    public function key(): string
    {
        return hash('sha256', $this->value);
    }
}
