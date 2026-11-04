<?php

declare(strict_types=1);

namespace App\Platform\Operations\Alerts\Notices;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts an alert to a Slack incoming webhook. Only in production (AGENT.md
 * § 7: no real third-party calls elsewhere; the list is written out here);
 * other environments write the message to the log instead. Short timeouts:
 * this may run at the end of a web request or inside the scheduler minute.
 */
final readonly class SlackWebhook
{
    /** Environments that call Slack for real. */
    public const array LIVE_ENVIRONMENTS = ['production'];

    public function __construct(private Application $app) {}

    public function post(string $url, string $text): bool
    {
        if (! in_array($this->app->environment(), self::LIVE_ENVIRONMENTS, true)) {
            Log::info('Operations alert for Slack (Slack is called only in production).', ['text' => $text]);

            return true;
        }

        try {
            $response = Http::connectTimeout(3)->timeout(5)->asJson()->post($url, ['text' => $text]);
        } catch (ConnectionException) {
            // Not the message: it holds the webhook URL, a secret.
            Log::error('Slack could not be reached for the operations alert.');

            return false;
        }

        if (! $response->successful()) {
            Log::error('Slack refused the operations alert (HTTP '.$response->status().').');

            return false;
        }

        return true;
    }
}
