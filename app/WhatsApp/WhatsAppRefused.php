<?php

declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Meta refused the message (a 4xx answer: the number is not on WhatsApp,
 * the template was paused, the token was revoked, …). Never retried: the
 * same request would be refused again. The message never holds the token.
 */
final class WhatsAppRefused extends WhatsAppUnavailable {}
