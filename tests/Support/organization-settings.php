<?php

declare(strict_types=1);

use App\Banking\BankAccounts;
use App\Models\Organization;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\LivewireHttp;

/*
 * Helpers for tests/Feature/OrganizationSettings (P01-05): Paystack's
 * account-name lookup, faked (no test reaches the network), and the
 * settings pages over HTTP.
 */

const PAYSTACK_RESOLVE = 'api.paystack.co/bank/resolve*';

/** GoProperty's own Paystack key is configured (it is not on a fresh install). */
function withPaystackKey(): void
{
    config(['services.paystack.secret_key' => 'sk_test_goproperty_platform']);
}

/**
 * A fresh fake HTTP client: Http::fake() adds to earlier stubs and the first
 * match wins, so each helper starts over. Stray requests stay refused.
 *
 * @param  array<string, mixed>  $stubs
 */
function fakePaystack(array $stubs): Factory
{
    Http::swap(new Factory(app('events')));
    Http::preventStrayRequests();

    return Http::fake($stubs);
}

/** No Paystack key (a fresh install): nothing may be sent. */
function withoutPaystackKey(): Factory
{
    config(['services.paystack.secret_key' => null]);

    return fakePaystack([]);
}

/** Paystack resolves every account to this name. */
function paystackResolves(string $name): Factory
{
    withPaystackKey();

    return fakePaystack([PAYSTACK_RESOLVE => Http::response([
        'status' => true,
        'message' => 'Account number resolved',
        'data' => ['account_number' => '0123456789', 'account_name' => $name, 'bank_id' => 9],
    ])]);
}

/** Paystack cannot resolve the number (the bank has no such account). */
function paystackNotFound(): Factory
{
    withPaystackKey();

    return fakePaystack([PAYSTACK_RESOLVE => Http::response(['status' => false, 'message' => 'Could not resolve account name. Check parameters or try again.'], 422)]);
}

/** Paystack is down. */
function paystackDown(): Factory
{
    withPaystackKey();

    return fakePaystack([PAYSTACK_RESOLVE => Http::response(['status' => false, 'message' => 'Service unavailable'], 503)]);
}

function addBankAccount(Organization $organization, User $owner, string $number = '0123456789', string $name = 'Adeyemi Realty Ltd', string $bank = '058'): OrganizationBankAccount
{
    return app(OrganizationContext::class)->run($organization, fn () => app(BankAccounts::class)->add($owner, $bank, $number, $name));
}

/** A settings page and its Livewire snapshot, as the user's browser loads it. */
function settingsPage(User $user, string $page, string $slug = 'acme-homes'): string
{
    $response = test()->actingAs($user)->get("/o/{$slug}/settings/{$page}")->assertOk();

    return LivewireHttp::snapshot($response);
}
