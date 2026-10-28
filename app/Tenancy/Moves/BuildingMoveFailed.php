<?php

declare(strict_types=1);

namespace App\Tenancy\Moves;

use RuntimeException;

/** A building move could not be done as asked; the whole move is rolled back. */
final class BuildingMoveFailed extends RuntimeException {}
