<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\Organization;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The last number the current organization gave out for one document type
 * in one year (DATABASE_SCHEMA.md § 4 "document_counters"). Written only by
 * DocumentNumberer, under a row lock inside the issuing transaction; never
 * deleted (the numbers it counted are on issued documents).
 *
 * History: a management handover (P03-14) leaves counters with the
 * organization that issued the documents, which keeps its numbering.
 *
 * @property int $id
 * @property int $organization_id
 * @property DocumentType $doc_type
 * @property int $year
 * @property int $last_seq
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 */
final class DocumentCounter extends Model
{
    use BelongsToOrganization;

    /** @var list<string> */
    protected $fillable = ['doc_type', 'year', 'last_seq'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'year' => 'integer',
            'last_seq' => 'integer',
        ];
    }
}
