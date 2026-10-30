<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Mailgun's webhook signing key: its bounce and complaint calls must be
    // signed with it when set (P05-04, App\Mail\Suppression\EmailWebhooks).
    'mailgun' => [
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // GoProperty's own Paystack account (its subscription income, BILLING_PAYMENTS.md).
    // Organizations' bank account names are checked with this key too: the
    // account-name lookup moves no money, so using the platform's key keeps
    // invariant 8 (ONBOARDING_COMPLIANCE.md § 0, § 4.1). With no key the
    // accounts are saved as "name not checked" (App\Banking\BankAccounts).
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'timeout' => (int) env('PAYSTACK_TIMEOUT', 8),
    ],

    // Organizations' own WhatsApp Business accounts (ARCHITECTURE.md § 6.7).
    // `log` (the default until P05-06 builds the Cloud API driver) sends
    // nothing and is never offered in production; `none` switches it off.
    // SMS (P05-02): GoProperty's own GoText account. `gotext` sends only in
    // production; any other driver, or any other environment, uses the log
    // stand-in (App\Providers\AppServiceProvider).
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'gotext' => [
        'token' => env('GOTEXT_API_TOKEN'),
        'sender_id' => env('GOTEXT_SENDER_ID', 'GoProperty'),
        'base_url' => env('GOTEXT_BASE_URL', 'https://app.gotext.ng'),
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
