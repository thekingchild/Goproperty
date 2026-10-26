<?php

declare(strict_types=1);

namespace App\Hosting;

enum RealtimeDriver: string
{
    case Polling = 'polling';
    case Reverb = 'reverb';
}
