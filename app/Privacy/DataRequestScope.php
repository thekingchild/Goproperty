<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Enums\Concerns\HasValues;

/** `data_requests.scope`: one tenant of an organization, or the whole organization. */
enum DataRequestScope: string
{
    use HasValues;

    case Tenant = 'tenant';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'One tenant',
            self::Organization => 'Whole organization',
        };
    }
}
