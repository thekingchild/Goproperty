<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Notices;

use App\Platform\Operations\Alerts\AlertRule;
use App\Platform\Operations\Alerts\Lagos;
use Carbon\CarbonImmutable;

/** What one notice says, the same on Slack and in the email. No personal data. */
final readonly class AlertNotice
{
    public function __construct(
        public NoticeKind $kind,
        public ?AlertRule $rule,
        public string $text,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $at,
        public ?string $dashboardUrl,
    ) {}

    public function title(string $brand): string
    {
        return "[{$brand}] {$this->kind->prefix()}: ".($this->rule?->label() ?? 'operations alerts');
    }

    /** @return list<string> */
    public function lines(): array
    {
        return array_values(array_filter([
            $this->text,
            match ($this->kind) {
                NoticeKind::Raised => $this->startedAt !== null ? 'Found at '.Lagos::time($this->startedAt).'.' : null,
                NoticeKind::Reminder => $this->startedAt !== null && $this->at !== null ? 'Open since '.Lagos::time($this->startedAt).' ('.Lagos::duration($this->startedAt, $this->at).').' : null,
                NoticeKind::Cleared => $this->startedAt !== null && $this->at !== null ? 'It was open for '.Lagos::duration($this->startedAt, $this->at).'.' : null,
                NoticeKind::Test => 'Sent from Settings → Operations alerts to check where alerts go. Nothing is wrong.',
            },
        ]));
    }

    /** Slack's plain-text message (incoming webhooks take `text`). */
    public function slackText(string $brand): string
    {
        $icon = match ($this->kind) {
            NoticeKind::Raised => ':rotating_light:',
            NoticeKind::Reminder => ':warning:',
            NoticeKind::Cleared => ':white_check_mark:',
            NoticeKind::Test => ':information_source:',
        };

        return trim("{$icon} *{$this->title($brand)}*\n".implode("\n", $this->lines()).($this->dashboardUrl !== null ? "\n<{$this->dashboardUrl}|System operations>" : ''));
    }
}
