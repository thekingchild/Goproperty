<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Modules\WhatsApp\Enums\OptInSource;

/**
 * A tenant's consent to the agency's WhatsApp messages at one number
 * (Meta policy; DATABASE_SCHEMA.md § 7 `whatsapp_opt_ins`),
 * organization-scoped. Open while `opted_out_at` is null. Written only by
 * OptIns\WhatsAppOptIns: the number, source and time it was given never
 * change; closing it sets the opt-out columns; never deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $tenant_profile_id
 * @property string $phone
 * @property CarbonImmutable $opted_in_at
 * @property OptInSource $source
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable|null $opted_out_at
 * @property OptInSource|null $opt_out_source
 * @property int|null $opted_out_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WhatsAppOptIn extends Model
{
    use BelongsToOrganization;

    protected $table = 'whatsapp_opt_ins';

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        self::updating(static function (self $optIn): void {
            if ($optIn->isDirty(['tenant_profile_id', 'phone', 'opted_in_at', 'source', 'recorded_by_user_id'])) {
                throw new LogicException('An opt-in keeps how and when it was given.');
            }
        });

        self::deleting(static fn (): never => throw new LogicException('Opt-ins are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opted_in_at' => 'datetime',
            'source' => OptInSource::class,
            'opted_out_at' => 'datetime',
            'opt_out_source' => OptInSource::class,
        ];
    }

    public function open(): bool
    {
        return $this->opted_out_at === null;
    }
}
