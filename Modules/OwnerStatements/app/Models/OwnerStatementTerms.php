<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * What the agency agreed with one property owner (P08-05;
 * DATABASE_SCHEMA.md § 4 `owner_statement_terms`): the commission on rent
 * collected in basis points (null = not set: statements show "Commission
 * not set" and take none) and whether utilities collected pass to the
 * owner (the "share tenant contact details" column was dropped in P03-16).
 * Organization-scoped; written only by Statements\StatementTermsBook.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $owner_profile_id
 * @property int|null $commission_bps
 * @property bool $utilities_to_owner
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class OwnerStatementTerms extends Model
{
    use BelongsToOrganization;

    protected $table = 'owner_statement_terms';

    /** @var array<string, mixed> */
    protected $attributes = ['utilities_to_owner' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'owner_profile_id' => 'integer',
            'commission_bps' => 'integer',
            'utilities_to_owner' => 'boolean',
            'updated_by_user_id' => 'integer',
        ];
    }
}
