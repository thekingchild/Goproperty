<?php

declare(strict_types=1);

namespace App\WhatsApp;

use RuntimeException;

/** The organization cannot send WhatsApp messages (not connected, or the driver is off). */
final class WhatsAppUnavailable extends RuntimeException {}
