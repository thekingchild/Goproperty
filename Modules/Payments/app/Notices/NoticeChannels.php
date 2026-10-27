<?php

declare(strict_types=1);

namespace Modules\Payments\Notices;

use App\Models\User;

/**
 * Where a tenant hears about their payments (P03-10b, P03 decision 3): in
 * the app when they have a portal account (`database`), by email when an
 * address is known (the account's, else the tenant record's), and only
 * when no address is known, by the agency's WhatsApp (the stand-in sender
 * until P05-06) when it is available. Never SMS.
 */
final readonly class NoticeChannels
{
    public const string DATABASE = 'database';

    public const string MAIL = 'mail';

    public const string WHATSAPP = 'whatsapp';

    /**
     * @param  ?User  $user  the tenant's portal account
     * @param  ?string  $mailTo  the address the email goes to
     * @param  ?string  $phone  the number a WhatsApp message goes to
     */
    public function __construct(
        public ?User $user,
        public ?string $mailTo,
        public ?string $phone,
    ) {}

    /** @return list<string> */
    public function all(): array
    {
        return array_values(array_filter([
            $this->user !== null ? self::DATABASE : null,
            $this->mailTo !== null ? self::MAIL : null,
            $this->phone !== null ? self::WHATSAPP : null,
        ]));
    }

    /** Whether the email goes to the account's own address (so through the user's notification). */
    public function mailsTheUser(): bool
    {
        return $this->user !== null && $this->mailTo !== null && $this->mailTo === $this->user->email;
    }

    /**
     * How a copy reached the tenant, as the receipt says it
     * ("A copy was sent to the tenant by WhatsApp and email.").
     *
     * @return list<string>
     */
    public function printed(): array
    {
        return array_values(array_filter([
            $this->phone !== null ? 'WhatsApp' : null,
            $this->mailTo !== null ? 'email' : null,
        ]));
    }
}
