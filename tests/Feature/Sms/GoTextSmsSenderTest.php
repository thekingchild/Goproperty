<?php

declare(strict_types=1);

use App\Sms\DeliveryStatus;
use App\Sms\GoTextSmsSender;
use App\Sms\LogSmsSender;
use App\Sms\SmsFailed;
use App\Sms\SmsSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * P05-02: the GoText driver (docs/plans/sms-provider-2026-10-29.md), as
 * SocietyFlow calls GoText, and the rule that no environment but
 * production sends a real SMS (AGENT.md § 7).
 */

function goText(): GoTextSmsSender
{
    return new GoTextSmsSender('gt-secret-token', 'GoProperty', 'https://app.gotext.ng');
}

it('posts one message as JSON with the token in the body and the number without its plus, and reads the uid, pages and cost', function () {
    Http::preventStrayRequests();
    Http::fake(['https://app.gotext.ng/api/http/sms/send' => Http::response([
        'status' => 'success',
        'data' => ['uid' => '6a1b2c3d', 'to' => '2348031112222', 'status' => 'Delivered', 'cost' => '2.50', 'sms_count' => 1],
    ])]);

    $receipt = goText()->send('+2348031112222', 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow (1 Jan).');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://app.gotext.ng/api/http/sms/send'
        && ! str_contains($request->url(), 'gt-secret-token')
        && $request->isJson()
        && $request->data() === [
            'api_token' => 'gt-secret-token',
            'recipient' => '2348031112222',
            'sender_id' => 'GoProperty',
            'type' => 'plain',
            'message' => 'Adeyemi Realty: rent of NGN 1,500,000 is due tomorrow (1 Jan).',
        ]);
    expect([$receipt->messageId, $receipt->providerStatus, $receipt->pages, $receipt->costKobo])->toBe(['6a1b2c3d', 'Delivered', 1, 250]);
});

it('takes a success whose data it cannot read, with no uid, pages or cost', function () {
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => 'sms reports with all details'])]);

    $receipt = goText()->send('+2348031112222', 'Hi');

    expect([$receipt->messageId, $receipt->pages, $receipt->costKobo])->toBe([null, null, null]);
});

it('fails, without the token, when GoText says error, answers 5xx or cannot be reached', function (Closure $fake, string $message) {
    $fake();

    try {
        goText()->send('+2348031112222', 'Hi');
        $this->fail('Expected SmsFailed');
    } catch (SmsFailed $failed) {
        expect($failed->getMessage())->toContain($message)->not->toContain('gt-secret-token');
    }
})->with([
    'error' => [fn () => Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'Insufficient balance'])]), 'Insufficient balance'],
    'server' => [fn () => Http::fake(['*' => Http::response('Bad gateway', 502)]), 'HTTP 502'],
    'offline' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timeout')]), 'could not be reached'],
]);

it('checks a message with GET /sms/{uid}, the token in the body, and reads its status word', function (string $word, ?DeliveryStatus $status) {
    Http::fake(['https://app.gotext.ng/api/http/sms/6a1b2c3d' => Http::response(['status' => 'success', 'data' => ['uid' => '6a1b2c3d', 'status' => $word]])]);

    $report = goText()->status('6a1b2c3d');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->data() === ['api_token' => 'gt-secret-token'] && ! str_contains($request->url(), 'token'));
    expect($report->status)->toBe($status)->and($report->providerStatus)->toBe($word);
})->with([
    ['Delivered', DeliveryStatus::Delivered],
    ['DELIVRD', null],
    ['Undelivered', DeliveryStatus::Undelivered],
    ['Failed', DeliveryStatus::Undelivered],
    ['Rejected', DeliveryStatus::Undelivered],
    ['Sent', null],
    ['Pending', null],
]);

it('sends real SMS only in production, with GoText chosen and a token set; the log stand-in everywhere else', function () {
    $resolve = function (): SmsSender {
        app()->forgetInstance(SmsSender::class);

        return app(SmsSender::class);
    };

    config(['services.sms.driver' => 'gotext', 'services.gotext.token' => 'gt-secret-token']);
    expect($resolve())->toBeInstanceOf(LogSmsSender::class);

    foreach (['local', 'staging', 'testing'] as $environment) {
        app()->detectEnvironment(fn (): string => $environment);
        expect($resolve())->toBeInstanceOf(LogSmsSender::class);
    }

    app()->detectEnvironment(fn (): string => 'production');
    expect($resolve())->toBeInstanceOf(GoTextSmsSender::class);

    config(['services.gotext.token' => '']);
    expect($resolve())->toBeInstanceOf(LogSmsSender::class);

    config(['services.gotext.token' => 'gt-secret-token', 'services.sms.driver' => 'log']);
    expect($resolve())->toBeInstanceOf(LogSmsSender::class);

    app()->detectEnvironment(fn (): string => 'testing');
    app()->forgetInstance(SmsSender::class);
});
