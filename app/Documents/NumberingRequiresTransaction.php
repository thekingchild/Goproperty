<?php

declare(strict_types=1);

namespace App\Documents;

use LogicException;

/**
 * A document number was asked for outside a database transaction. The
 * number must be taken in the transaction that issues the document, so the
 * counter stays locked until the document is saved and a failed issue gives
 * the number back (ARCHITECTURE.md § 6.5).
 */
final class NumberingRequiresTransaction extends LogicException
{
    public static function for(DocumentType $type): self
    {
        return new self("{$type->label()} numbers can only be taken inside the transaction that issues the document.");
    }
}
