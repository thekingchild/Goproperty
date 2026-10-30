<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Meta;

use RuntimeException;

/** Meta answered 5xx or something unreadable: it did not take the request. */
class MetaUnavailable extends RuntimeException {}
