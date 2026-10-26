<?php

declare(strict_types=1);

namespace App\Pdf;

enum Orientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';
}
