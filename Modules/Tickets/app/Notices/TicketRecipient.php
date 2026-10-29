<?php

declare(strict_types=1);

namespace Modules\Tickets\Notices;

use App\Models\User;

/**
 * One person to tell about a ticket (Notices\TicketRecipients): their side
 * (`tenant`: the portal's ticket page; `staff`: the workspace's; `owner`:
 * the building's owner, the owner portal's page, P04-06), the key
 * that makes them one recipient (`user:{id}`, or `tenant:{id}` for a
 * tenant with an email address and no portal account), their name, the
 * account told in the app (`database`) and the address the email goes to
 * (docs/plans/P04-subtasks.md default 9: email and in the app). A tenant
 * always has an address here (phone-only tenants are told nothing until
 * P05); a member without one is told in the app only.
 */
final readonly class TicketRecipient
{
    public const string TENANT = 'tenant';

    public const string STAFF = 'staff';

    /** The building's owner, told about a ticket shared with them (P04-06). */
    public const string OWNER = 'owner';

    public const string DATABASE = 'database';

    public const string MAIL = 'mail';

    public function __construct(
        public string $side,
        public string $recipient,
        public string $name,
        public ?User $user,
        public ?string $mailTo,
    ) {}

    public static function staff(User $user): self
    {
        $email = trim((string) $user->email);

        return new self(self::STAFF, 'user:'.$user->id, (string) $user->name, $user, $email !== '' ? $email : null);
    }

    /** The building owner's portal account (P04-06): in the app, and by email when it has an address. */
    public static function owner(User $user): self
    {
        $email = trim((string) $user->email);

        return new self(self::OWNER, 'user:'.$user->id, (string) $user->name, $user, $email !== '' ? $email : null);
    }

    public function isOwner(): bool
    {
        return $this->side === self::OWNER;
    }

    public function isTenant(): bool
    {
        return $this->side === self::TENANT;
    }

    /**
     * Where they are told: in the app when they have an account, by email
     * when an address is known.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_values(array_filter([
            $this->user !== null ? self::DATABASE : null,
            $this->mailTo !== null ? self::MAIL : null,
        ]));
    }

    /** Whether the email goes to the account's own address (so through the account's notification). */
    public function mailsTheUser(): bool
    {
        return $this->user !== null && $this->mailTo !== null && trim((string) $this->user->email) === $this->mailTo;
    }
}
