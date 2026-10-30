<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Mail\Templates\Placeholder;

/**
 * Something GoProperty may tell a person by the agency's WhatsApp
 * (P05-06; ARCHITECTURE.md § 6.7): its key (the WhatsAppMessage's
 * `event`), how the agency's settings name it, and the placeholders a
 * template variable may be mapped to — the whitelist, the same values the
 * code fills in for that message's email and SMS templates.
 *
 * `fallsBackToSms` (reminders): the agency may put SMS after (or before)
 * WhatsApp in the event's channel order; `smsRung` says whether SMS goes on
 * this rung at all (docs/plans/P05-subtasks.md default 14). Invitations,
 * sign-in links and payment notices never use SMS (P03 decision 3), so
 * their order is WhatsApp alone.
 */
final readonly class WhatsAppEvent
{
    /** @param  list<Placeholder>  $placeholders */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public array $placeholders,
        public bool $fallsBackToSms = false,
        public bool $smsRung = false,
        public int $order = 100,
    ) {}

    /** @return list<string> */
    public function placeholderNames(): array
    {
        return array_map(static fn (Placeholder $placeholder): string => $placeholder->name, $this->placeholders);
    }

    public function placeholder(string $name): ?Placeholder
    {
        foreach ($this->placeholders as $placeholder) {
            if ($placeholder->name === $name) {
                return $placeholder;
            }
        }

        return null;
    }

    /**
     * The channels the event's order may hold: WhatsApp, and SMS for a
     * reminder on an SMS rung.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return $this->fallsBackToSms && $this->smsRung ? ['whatsapp', 'sms'] : ['whatsapp'];
    }

    /**
     * The placeholders of several templates, the first one's wording kept
     * for a name they share (an email template and its SMS version).
     *
     * @param  list<list<Placeholder>>  $lists
     * @return list<Placeholder>
     */
    public static function merged(array $lists): array
    {
        $merged = [];

        foreach ($lists as $list) {
            foreach ($list as $placeholder) {
                $merged[$placeholder->name] ??= $placeholder;
            }
        }

        return array_values($merged);
    }
}
