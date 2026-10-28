<?php

declare(strict_types=1);

namespace Modules\Payments\Contracts;

use RuntimeException;

/** The tenant's credit is no longer the amount shown (it was used, or settled meanwhile); nothing changed. */
final class CreditChanged extends RuntimeException {}
