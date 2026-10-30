<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\WhatsApp\Models\WhatsAppConnection;

/**
 * The stand-in for Meta outside production (AGENT.md § 7: no real
 * messages anywhere else): checks accept every connection, a sync fetches
 * nothing (the templates already saved stay as they are), and a send keeps
 * the message in memory for this process (tests read sent()) and logs
 * the template and masked number, never the values.
 */
final class LogCloudApi implements CloudApi
{
    /** @var list<array{to: string, template: string, language: string, components: list<array<string, mixed>>}> */
    private array $sent = [];

    public function name(): string
    {
        return 'log';
    }

    public function check(WhatsAppConnection $connection): ConnectionCheck
    {
        Log::info('WhatsApp connection not checked with Meta (log transport)', ['organization_id' => $connection->organization_id]);

        return new ConnectionCheck(CheckResult::Accepted, $connection->display_phone_number, $connection->verified_name);
    }

    public function templates(WhatsAppConnection $connection): ?array
    {
        Log::info('WhatsApp templates not fetched from Meta (log transport)', ['organization_id' => $connection->organization_id]);

        return null;
    }

    public function send(WhatsAppConnection $connection, string $to, string $template, string $language, array $components): string
    {
        $this->sent[] = ['to' => $to, 'template' => $template, 'language' => $language, 'components' => $components];

        Log::info('WhatsApp message not sent (log transport)', [
            'organization_id' => $connection->organization_id,
            'template' => $template,
            'to' => strlen($to) > 8 ? substr($to, 0, 4).' ••• '.substr($to, -4) : '•••',
        ]);

        return 'log-'.Str::uuid();
    }

    /** @return list<array{to: string, template: string, language: string, components: list<array<string, mixed>>}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
