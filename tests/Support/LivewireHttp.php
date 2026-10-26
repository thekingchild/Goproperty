<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use LogicException;
use Tests\TestCase;

/**
 * Drives a Livewire component the way the browser does: the snapshot from
 * the rendered page, then POSTs to Livewire's own update endpoint with the
 * full middleware stack. Livewire::test() skips route middleware on
 * updates, so it cannot show that a component inside /o/{organization}
 * gets its organization context (and membership check) on every update.
 */
final class LivewireHttp
{
    /** The first component snapshot in a rendered page. */
    public static function snapshot(TestResponse|string $page): string
    {
        $html = $page instanceof TestResponse ? (string) $page->getContent() : $page;

        if (preg_match('/wire:snapshot="([^"]+)"/', $html, $match) !== 1) {
            throw new LogicException('No Livewire component on the page.');
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    /**
     * One update request: property updates, then method calls.
     *
     * @param  TestCase  $test  the running test (Pest's test() proxy is fine)
     * @param  array<string, mixed>  $updates
     * @param  list<array{0: string, 1?: list<mixed>}>  $calls  [method, params]
     */
    public static function update(object $test, string $snapshot, array $updates = [], array $calls = []): TestResponse
    {
        // Livewire keeps per-request state (e.g. which route's persistent
        // middleware it last applied) until the request ends; production
        // starts every request fresh, so the test does too.
        app('livewire')->flushState();

        $response = $test->withHeaders(['X-Livewire' => '1'])->postJson(EndpointResolver::updatePath(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) $updates,
                'calls' => array_map(static fn (array $call): array => ['method' => $call[0], 'params' => $call[1] ?? [], 'metadata' => (object) []], $calls),
            ]],
        ]);

        // Headers stick to the test case; later page loads are not Livewire requests.
        $test->withoutHeader('X-Livewire');

        return $response;
    }

    /** The component's snapshot after an update, for the next one. */
    public static function next(TestResponse $response): string
    {
        $snapshot = $response->json('components.0.snapshot');

        return is_string($snapshot) ? $snapshot : throw new LogicException('No snapshot in the response.');
    }

    public static function html(TestResponse $response): string
    {
        $html = $response->json('components.0.effects.html');

        return is_string($html) ? $html : '';
    }

    public static function redirect(TestResponse $response): ?string
    {
        $redirect = $response->json('components.0.effects.redirect');

        return is_string($redirect) ? $redirect : null;
    }

    /** @return array<string, list<string>> */
    public static function errors(TestResponse $response): array
    {
        $snapshot = json_decode(self::next($response), true);
        $errors = is_array($snapshot) ? ($snapshot['memo']['errors'] ?? []) : [];

        return is_array($errors) ? $errors : [];
    }
}
