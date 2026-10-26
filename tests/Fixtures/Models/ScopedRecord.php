<?php

declare(strict_types=1);

namespace Tests\Fixtures\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization-owned model for the tenancy tests. Its table lives in a
 * throwaway database (connection "tenancy_fixture"), so the real schema stays
 * GoProperty's own.
 *
 * @property int $id
 * @property string $name
 */
final class ScopedRecord extends Model
{
    use BelongsToOrganization;

    protected $connection = 'tenancy_fixture';

    protected $table = 'scoped_records';

    protected $guarded = [];
}
