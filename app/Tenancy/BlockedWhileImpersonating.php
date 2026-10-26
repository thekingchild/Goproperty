<?php

declare(strict_types=1);

namespace App\Tenancy;

use Attribute;

/**
 * Marks a controller action, Livewire component or method, or policy
 * method that GoProperty staff may never use while impersonating, in read-
 * only or write mode (PLATFORM_CONSOLE.md § 6, "always blocked"): money
 * actions (confirming or rejecting payments, voiding receipts), bank
 * accounts and Paystack keys (also viewing them), billing transfers, sign-in
 * details (password, 2FA, passkeys, email), members and roles, deleting
 * anything, and data import commit or rollback (DATA_IMPORT.md § 5).
 *
 * Most features need no attribute: their permission is classed in
 * OrganizationPermission::blockedWhileImpersonating(), and policy abilities
 * named delete…/forceDelete…/destroy…/remove…/purge…/void… are refused by
 * name. Use the attribute for what neither covers. ImpersonationGuard
 * enforces it on HTTP requests, Livewire calls and Gate checks.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class BlockedWhileImpersonating {}
