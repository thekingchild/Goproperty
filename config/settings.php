<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings registry (PLATFORM_CONSOLE.md § 9.8)
|--------------------------------------------------------------------------
|
| Every setting is declared once: type (string, integer, boolean, json),
| default, rules (for the value) and item_rules (for list or object items,
| keyed like "*" or "*.question"), scope (global or organization), html
| (fields cleaned with the safe-HTML policy: "" for the whole value),
| whether it is a secret, the console permission that may change it, and
| whether changing it needs step-up. Modules declare their own keys with
| SettingsRegistry::define().
|
| theme.*, site.* and seo.* are drafted, previewed and published as one
| version (App\Theme\ThemeManager); custom_code.* and auth.* apply on save.
|
*/

$plain = 'regex:/^[^<>]*$/';
$hex = 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';
// An uploaded image or file, stored by App\Theme\BrandAssets as "role/hash.ext".
$asset = 'regex:/^[a-z_]+\/[a-f0-9]{16,64}\.(png|jpg|webp|svg|ico)$/';
$phone = 'regex:/^\+?[0-9 ()\-]{7,20}$/';

return [

    'definitions' => [

        // ---------------------------------------------------------------
        // Brand & appearance (§ 9.2)
        // ---------------------------------------------------------------

        'theme.name' => ['type' => 'string', 'default' => 'GoProperty', 'rules' => ['required', 'string', 'max:120', $plain], 'permission' => 'settings.theme'],
        'theme.short_name' => ['type' => 'string', 'default' => 'GoProperty', 'rules' => ['required', 'string', 'max:30', $plain], 'permission' => 'settings.theme'],
        'theme.tagline' => ['type' => 'string', 'default' => 'Property management for Nigeria', 'rules' => ['required', 'string', 'max:160', $plain], 'permission' => 'settings.theme'],
        'theme.legal_name' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:160', $plain], 'permission' => 'settings.theme'],
        'theme.address' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:255', $plain], 'permission' => 'settings.theme'],
        'theme.support_email' => ['type' => 'string', 'rules' => ['nullable', 'email', 'max:254'], 'permission' => 'settings.theme'],
        'theme.support_phone' => ['type' => 'string', 'rules' => ['nullable', 'string', $phone], 'permission' => 'settings.theme'],
        'theme.support_whatsapp' => ['type' => 'string', 'rules' => ['nullable', 'string', $phone], 'permission' => 'settings.theme'],
        'theme.logo_light' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        'theme.logo_dark' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        'theme.logo_mode' => ['type' => 'string', 'default' => 'logo_with_text', 'rules' => ['required', 'in:logo_only,logo_with_text'], 'permission' => 'settings.theme'],
        'theme.icon' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        'theme.favicon' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        'theme.color_primary' => ['type' => 'string', 'default' => '#1D4ED8', 'rules' => ['required', 'string', $hex], 'permission' => 'settings.theme'],
        'theme.color_accent' => ['type' => 'string', 'default' => '#F59E0B', 'rules' => ['required', 'string', $hex], 'permission' => 'settings.theme'],
        'theme.neutral' => ['type' => 'string', 'default' => 'slate', 'rules' => ['required', 'in:slate,gray,zinc,stone'], 'permission' => 'settings.theme'],
        'theme.font' => ['type' => 'string', 'default' => 'inter', 'rules' => ['required', 'in:inter,plus-jakarta-sans,manrope,dm-sans'], 'permission' => 'settings.theme'],
        'theme.radius' => ['type' => 'string', 'default' => 'rounded', 'rules' => ['required', 'in:sharp,rounded,extra-rounded'], 'permission' => 'settings.theme'],
        'theme.hero_image' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        // Null: follow the brand (--hero-deep).
        'theme.hero_overlay_color' => ['type' => 'string', 'rules' => ['nullable', 'string', $hex], 'permission' => 'settings.theme'],
        'theme.hero_overlay_opacity' => ['type' => 'integer', 'default' => 60, 'rules' => ['required', 'integer', 'between:0,100'], 'permission' => 'settings.theme'],
        'theme.auth_image' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.theme'],
        'theme.auth_overlay_color' => ['type' => 'string', 'rules' => ['nullable', 'string', $hex], 'permission' => 'settings.theme'],
        'theme.auth_overlay_opacity' => ['type' => 'integer', 'default' => 65, 'rules' => ['required', 'integer', 'between:0,100'], 'permission' => 'settings.theme'],
        'theme.auth_show_activity' => ['type' => 'boolean', 'default' => true, 'rules' => ['boolean'], 'permission' => 'settings.theme'],

        // ---------------------------------------------------------------
        // Public website (§ 9.3)
        // ---------------------------------------------------------------

        'site.hero_heading' => ['type' => 'string', 'default' => 'Rent in.', 'rules' => ['required', 'string', 'max:40', $plain], 'permission' => 'settings.site'],
        'site.hero_subheading' => ['type' => 'string', 'default' => 'Receipts out.', 'rules' => ['required', 'string', 'max:40', $plain], 'permission' => 'settings.site'],
        'site.hero_badge' => ['type' => 'string', 'default' => 'Built for Nigerian property managers', 'rules' => ['nullable', 'string', 'max:60', $plain], 'permission' => 'settings.site'],
        'site.hero_lede' => [
            'type' => 'string',
            'default' => 'GoProperty keeps your buildings, tenants, leases and utility bills in one place. Tenants pay you directly, and every confirmed payment gets a receipt automatically.',
            'rules' => ['required', 'string', 'max:240', $plain],
            'permission' => 'settings.site',
        ],
        'site.hero_trust' => [
            'type' => 'json',
            'default' => ['14-day free trial', 'No card required', 'Cancel anytime'],
            'rules' => ['array', 'size:3'],
            'item_rules' => ['*' => ['required', 'string', 'max:30', $plain]],
            'permission' => 'settings.site',
        ],
        'site.auth_headline' => ['type' => 'string', 'default' => 'Manage properties, rent and tenants in one place.', 'rules' => ['required', 'string', 'max:80', $plain], 'permission' => 'settings.site'],
        'site.auth_benefits' => [
            'type' => 'json',
            'default' => ['Collect & match rent automatically', 'Instant receipts for tenants', 'Reports for every landlord'],
            'rules' => ['array', 'size:3'],
            'item_rules' => ['*' => ['required', 'string', 'max:50', $plain]],
            'permission' => 'settings.site',
        ],
        'site.auth_trustline' => ['type' => 'string', 'default' => 'Bank-grade security · NDPA compliant · Made for Nigeria', 'rules' => ['required', 'string', 'max:80', $plain], 'permission' => 'settings.site'],
        'site.announcement' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:140', $plain], 'permission' => 'settings.site'],
        'site.announcement_link' => ['type' => 'string', 'rules' => ['nullable', 'url:https', 'max:500'], 'permission' => 'settings.site'],
        'site.footer_text' => ['type' => 'string', 'default' => 'Property management and rent collection for Nigerian agencies, landlords and tenants.', 'rules' => ['required', 'string', 'max:200', $plain], 'permission' => 'settings.site'],
        'site.social_links' => [
            'type' => 'json',
            'default' => [],
            'rules' => ['array:x,linkedin,instagram,facebook,youtube,whatsapp'],
            'item_rules' => ['*' => ['nullable', 'url:https', 'max:500']],
            'permission' => 'settings.site',
        ],
        'site.faq' => [
            'type' => 'json',
            'default' => [
                ['question' => 'Does GoProperty hold my tenants\' money?', 'answer' => '<p>No. Tenants pay into your own bank account or your own Paystack account. GoProperty matches and records the payment and issues the receipt — it never receives, holds or splits rent.</p>'],
                ['question' => 'Can I bring my existing tenants and running leases?', 'answer' => '<p>Yes. Import owners, properties, units, tenants, ongoing leases with their "paid until" dates and opening balances from Excel or CSV. A dry run shows every row before anything is saved, and imported leases are never back-billed.</p>'],
                ['question' => 'How is the price worked out?', 'answer' => '<p>By the number of units (blocks/apartments) you manage: a base price that includes some units, plus a fee for each extra unit. Pay monthly, or yearly and get two months free. VAT is added at 7.5%.</p>'],
                ['question' => 'What happens when the free trial ends?', 'answer' => '<p>Pick a plan and pay by card (Paystack) or bank transfer. Your data stays exactly as you left it.</p>'],
                ['question' => 'Can landlords and tenants log in too?', 'answer' => '<p>Yes, with the same login page. Landlords see read-only statements for their buildings; tenants see their bills, receipts and tickets — across every agency they rent from.</p>'],
                ['question' => 'What are Transaction Tiers?', 'answer' => '<p>Limits on how much money an agency manages each year. Tier 1 is active straight away; higher tiers need your CAC and SCUML documents. We\'ll tell you before you get close to your limit.</p>'],
            ],
            'rules' => ['array', 'max:30'],
            'item_rules' => ['*' => ['array:question,answer'], '*.question' => ['required', 'string', 'max:140', $plain], '*.answer' => ['required', 'string', 'max:5000']],
            'html' => ['answer'],
            'permission' => 'settings.site',
        ],
        'site.privacy_policy' => [
            'type' => 'string',
            'default' => '<h2>Privacy policy</h2><p>This page explains how GoProperty handles personal data under the Nigeria Data Protection Act 2023. Replace this text with your published policy.</p>',
            'rules' => ['required', 'string', 'max:100000'],
            'html' => [''],
            'permission' => 'settings.site',
        ],
        'site.terms' => [
            'type' => 'string',
            'default' => '<h2>Terms of service</h2><p>These terms govern the use of GoProperty. Replace this text with your published terms.</p>',
            'rules' => ['required', 'string', 'max:100000'],
            'html' => [''],
            'permission' => 'settings.site',
        ],

        // ---------------------------------------------------------------
        // Search & sharing (§ 9.4)
        // ---------------------------------------------------------------

        // Null: theme.name + tagline.
        'seo.title' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:70', $plain], 'permission' => 'settings.seo'],
        'seo.title_pattern' => ['type' => 'string', 'default' => '{page} · {site}', 'rules' => ['required', 'string', 'max:70', $plain, 'regex:/\{page\}/'], 'permission' => 'settings.seo'],
        'seo.description' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:320', $plain], 'permission' => 'settings.seo'],
        'seo.share_image' => ['type' => 'string', 'rules' => ['nullable', 'string', $asset], 'permission' => 'settings.seo'],
        // Null: on in production, off everywhere else (staging is never indexed).
        'seo.indexing_enabled' => ['type' => 'boolean', 'rules' => ['nullable', 'boolean'], 'permission' => 'settings.seo'],
        // Null: APP_URL.
        'seo.canonical_origin' => ['type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^https:\/\/[a-z0-9.\-]+(:\d{1,5})?$/'], 'permission' => 'settings.seo', 'step_up' => true],

        // ---------------------------------------------------------------
        // Custom code (§ 9.5): public marketing pages only; apply on save
        // ---------------------------------------------------------------

        'custom_code.head' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:51200', 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/'], 'permission' => 'settings.custom_code', 'step_up' => true],
        'custom_code.body_start' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:51200', 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/'], 'permission' => 'settings.custom_code', 'step_up' => true],
        'custom_code.body_end' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:51200', 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/'], 'permission' => 'settings.custom_code', 'step_up' => true],
        'custom_code.css' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:51200', 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/', 'not_regex:/<\/style/i'], 'permission' => 'settings.custom_code', 'step_up' => true],
        'custom_code.allowed_hosts' => [
            'type' => 'json',
            'default' => ['www.googletagmanager.com', 'www.google-analytics.com'],
            'rules' => ['array', 'max:50'],
            'item_rules' => ['*' => ['required', 'string', 'regex:/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/']],
            'permission' => 'settings.custom_code',
            'step_up' => true,
        ],

        // ---------------------------------------------------------------
        // Sign-in security (§ 9.6): applies on save
        // ---------------------------------------------------------------

        'auth.passkeys_enabled' => ['type' => 'boolean', 'default' => true, 'rules' => ['boolean'], 'permission' => 'settings.security'],
        // after_failures: the check shows after 3 failed sign-ins for the
        // account or the IP in 15 minutes; always: on every attempt.
        'auth.turnstile_mode' => ['type' => 'string', 'default' => 'after_failures', 'rules' => ['required', 'in:after_failures,always'], 'permission' => 'settings.security'],
        'auth.turnstile_site_key' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:100'], 'permission' => 'settings.security'],
        'auth.turnstile_secret_key' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:100'], 'secret' => true, 'permission' => 'settings.security', 'step_up' => true],
        // Failed sign-ins per identifier + IP before a pause; without Turnstile
        // keys the pause starts at the bot-check threshold (3) instead.
        'auth.lockout_after' => ['type' => 'integer', 'default' => 5, 'rules' => ['required', 'integer', 'between:3,20'], 'permission' => 'settings.security'],
        'auth.lockout_minutes' => ['type' => 'integer', 'default' => 15, 'rules' => ['required', 'integer', 'between:1,1440'], 'permission' => 'settings.security'],
        'auth.remember_days' => ['type' => 'integer', 'default' => 30, 'rules' => ['required', 'integer', 'between:1,365'], 'permission' => 'settings.security'],
        'auth.trust_device_days' => ['type' => 'integer', 'default' => 30, 'rules' => ['required', 'integer', 'between:1,365'], 'permission' => 'settings.security'],
        // Who must use 2FA: owners of Tier 2/3 organizations, all owners, or all agency staff.
        'auth.require_2fa_for' => ['type' => 'string', 'default' => 'tier_owners', 'rules' => ['required', 'in:tier_owners,all_owners,all_staff'], 'permission' => 'settings.security'],

        // ---------------------------------------------------------------
        // SMS credits (BILLING_PAYMENTS.md § 1.2; P05-03): finance-admin
        // ---------------------------------------------------------------

        // The price of one credit (one SMS page) in a bundle, in kobo, before VAT.
        'sms_credits.price_kobo' => ['type' => 'integer', 'default' => 600, 'rules' => ['required', 'integer', 'between:1,100000'], 'permission' => 'plans.manage'],
        // The bundles offered in the app, in credits.
        'sms_credits.bundles' => [
            'type' => 'json',
            'default' => [500, 1000, 2500, 5000],
            'rules' => ['required', 'array', 'list', 'min:1', 'max:6'],
            'item_rules' => ['*' => ['required', 'integer', 'distinct', 'between:10,100000']],
            'permission' => 'plans.manage',
        ],
        // One organization's monthly allowance instead of its plan's (Enterprise: custom); null = the plan's.
        'sms_credits.monthly_allowance' => ['type' => 'integer', 'scope' => 'organization', 'rules' => ['nullable', 'integer', 'between:0,1000000'], 'permission' => 'plans.manage'],

        // ---------------------------------------------------------------
        // Bank transfers to GoProperty (BILLING_PAYMENTS.md § 5; P06-04):
        // the account shown on every open subscription invoice, with the
        // invoice number as the narration. finance-admin. Until all three
        // are set, invoices offer no bank transfer.
        // ---------------------------------------------------------------

        'billing.transfer_bank' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:80', $plain], 'permission' => 'plans.manage'],
        'billing.transfer_account_name' => ['type' => 'string', 'rules' => ['nullable', 'string', 'max:120', $plain], 'permission' => 'plans.manage'],
        // A Nigerian NUBAN: 10 digits.
        'billing.transfer_account_number' => ['type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'], 'permission' => 'plans.manage'],
        // Refunds above this (kobo, counting the payment's earlier refunds) need a second staff member's approval (P06-07b; PLATFORM_CONSOLE.md § 1). Default ₦100,000.
        'billing.refund_approval_above_kobo' => ['type' => 'integer', 'default' => 10_000_000, 'rules' => ['required', 'integer', 'between:0,100000000000'], 'permission' => 'refunds.configure'],

    ],

];
