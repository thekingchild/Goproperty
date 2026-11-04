<?php

declare(strict_types=1);

namespace App\Models;

use App\Privacy\Consents\ConsentDocument;
use App\Privacy\Consents\ConsentSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What one person agreed to, or withdrew, and when (P09-02; NDPA 2023
 * s. 26: the controller must be able to show the consent). Platform-level:
 * `organization_id` is the organization whose invitation was accepted.
 * Append-only: written only by App\Privacy\Consents; never changed or
 * deleted here (the NDPA erasure tooling blanks the IP address and browser
 * of an erased person's records with the query builder, keeping the proof).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $organization_id
 * @property ConsentDocument $document
 * @property bool $granted
 * @property string|null $version
 * @property ConsentSource $source
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $recorded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ConsentRecord extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('Consent records are append-only: record a new one.'));
        self::deleting(static fn (): never => throw new LogicException('Consent records are never deleted.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document' => ConsentDocument::class,
            'granted' => 'boolean',
            'source' => ConsentSource::class,
            'recorded_at' => 'datetime',
        ];
    }
}
