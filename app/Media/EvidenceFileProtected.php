<?php

declare(strict_types=1);

namespace App\Media;

use LogicException;

/** An evidence file was about to be deleted (AGENT.md § 3 rule 7, ARCHITECTURE.md § 9). */
final class EvidenceFileProtected extends LogicException {}
