<?php

declare(strict_types=1);

namespace Modules\Import\Batches;

use RuntimeException;

/** An import step that cannot happen now; the message is for the agency. */
final class ImportRefused extends RuntimeException {}
