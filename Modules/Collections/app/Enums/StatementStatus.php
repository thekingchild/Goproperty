<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * An uploaded statement's state (`bank_statement_imports.status`): its
 * lines are read on the `imports` queue (processing), then it is ready
 * (with its report) or failed (with the reason; the file is kept).
 */
enum StatementStatus: string
{
    use HasValues;

    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Processing => 'Reading',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    /** Flux badge colour. */
    public function color(): string
    {
        return match ($this) {
            self::Processing => 'amber',
            self::Ready => 'green',
            self::Failed => 'red',
        };
    }
}
