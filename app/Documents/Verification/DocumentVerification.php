<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Models\Organization;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A verification code issued for one document of the current organization
 * (DATABASE_SCHEMA.md § 4): which kind of document (the resolver's key in
 * DocumentVerifiers) and the code. Written only by VerificationCodes; never
 * changed or deleted afterwards, since a printed code must keep working (an
 * issued document is history and stays with the organization that issued
 * it, even after a management handover).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind
 * @property string $code
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
final class DocumentVerification extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['kind', 'code'];
}
