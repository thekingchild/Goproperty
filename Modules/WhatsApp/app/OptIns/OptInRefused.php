<?php

declare(strict_types=1);

namespace Modules\WhatsApp\OptIns;

use RuntimeException;

/** An opt-in that cannot be recorded, with a message for the person. */
final class OptInRefused extends RuntimeException {}
