<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use RuntimeException;

/** A template change that cannot be made; nothing was saved. */
final class TemplateRefused extends RuntimeException {}
