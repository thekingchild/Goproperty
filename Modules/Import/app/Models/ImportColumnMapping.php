<?php

declare(strict_types=1);

namespace Modules\Import\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A column mapping the organization saved for reuse (DATABASE_SCHEMA.md
 * § 8A, DATA_IMPORT.md § 2.2). Organization-scoped.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind the target key
 * @property string $name
 * @property array<string, string> $mapping normalised header => column key
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ImportColumnMapping extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['kind', 'name', 'mapping', 'created_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }
}
