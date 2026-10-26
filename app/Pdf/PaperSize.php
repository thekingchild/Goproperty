<?php

declare(strict_types=1);

namespace App\Pdf;

enum PaperSize: string
{
    case A4 = 'a4';
    case Letter = 'letter';

    /** @return array{0: float, 1: float} width and height in inches, portrait */
    public function inches(): array
    {
        return match ($this) {
            self::A4 => [8.27, 11.69],
            self::Letter => [8.5, 11.0],
        };
    }
}
