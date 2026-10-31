<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Audit\AuditEntry;
use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\User;
use App\Platform\Customers\CustomerUser;
use App\Policies\AuditLogEntryPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * The console's read model of the audit log (PLATFORM_CONSOLE.md § 7,
 * P06-07c): every `activity_log` entry across organizations. Read-only:
 * entries are written through activity() as App\Audit\AuditEntry and are
 * never changed or deleted. Authorized by AuditLogEntryPolicy.
 *
 * The causer is resolved by its type without loading the subject, which
 * may be an organization-scoped model (no OrganizationContext here).
 *
 * @property-read CustomerUser|null $causerUser
 * @property-read PlatformStaff|null $causerStaff
 * @property-read PlatformStaff|null $impersonator
 */
#[UsePolicy(AuditLogEntryPolicy::class)]
final class AuditLogEntry extends AuditEntry
{
    public const string BY_CUSTOMER = 'customer';

    public const string BY_STAFF = 'staff';

    public const string BY_SYSTEM = 'system';

    protected static function booted(): void
    {
        parent::booted();

        self::saving(fn (): never => throw new LogicException('The console reads the audit log; entries are written through activity().'));
    }

    /** @return BelongsTo<CustomerUser, $this> */
    public function causerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'causer_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function causerStaff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'causer_id');
    }

    /** @return BelongsTo<PlatformStaff, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'impersonator_staff_id');
    }

    /** @return array<string, string> */
    public static function causerKinds(): array
    {
        return [self::BY_CUSTOMER => 'Customer user', self::BY_STAFF => 'GoProperty staff', self::BY_SYSTEM => 'System'];
    }

    /**
     * Entries about the organization, or carrying it in
     * `properties.organization_id` (a JSON path both MySQL 8 and MariaDB
     * 10.6+ read the same way: JSON_UNQUOTE(JSON_EXTRACT(...)), compared as
     * text so a number and a numeric string both match).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function forOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where(fn (Builder $either) => $either
            ->where(fn (Builder $subject) => $subject->where('subject_type', (new Organization)->getMorphClass())->where('subject_id', $organizationId))
            ->orWhere('properties->organization_id', (string) $organizationId));
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function causedByKind(Builder $query, string $kind): Builder
    {
        return match ($kind) {
            self::BY_CUSTOMER => $query->where('causer_type', (new User)->getMorphClass()),
            self::BY_STAFF => $query->where('causer_type', (new PlatformStaff)->getMorphClass()),
            self::BY_SYSTEM => $query->whereNull('causer_type'),
            default => $query,
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function causedByUser(Builder $query, int $userId): Builder
    {
        return $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $userId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function causedByStaff(Builder $query, int $staffId): Builder
    {
        return $query->where('causer_type', (new PlatformStaff)->getMorphClass())->where('causer_id', $staffId);
    }

    public function causerKind(): ?string
    {
        return match ($this->causer_type) {
            null => self::BY_SYSTEM,
            (new User)->getMorphClass() => self::BY_CUSTOMER,
            (new PlatformStaff)->getMorphClass() => self::BY_STAFF,
            default => null,
        };
    }

    /** Who did it, by name; "Customer user #12" once the account is gone. */
    public function causerName(): string
    {
        return match ($this->causerKind()) {
            self::BY_SYSTEM => 'System',
            self::BY_CUSTOMER => $this->causerUser !== null ? $this->causerUser->name : 'Customer user #'.$this->causer_id,
            self::BY_STAFF => $this->causerStaff !== null ? $this->causerStaff->name : 'Staff member #'.$this->causer_id,
            default => class_basename((string) $this->causer_type).' #'.$this->causer_id,
        };
    }

    public function causerDetail(): ?string
    {
        return match ($this->causerKind()) {
            self::BY_CUSTOMER => $this->causerUser->email ?? 'Customer user',
            self::BY_STAFF => 'GoProperty staff',
            self::BY_SYSTEM => null,
            default => (string) $this->causer_type,
        };
    }

    public function subjectLabel(): ?string
    {
        return $this->subject_type !== null ? class_basename((string) $this->subject_type).' #'.$this->subject_id : null;
    }

    public function isAboutOrganization(): bool
    {
        return $this->subject_type === (new Organization)->getMorphClass() && $this->subject_id !== null;
    }

    /** The organization the entry concerns, if it names one. */
    public function organizationId(): ?int
    {
        if ($this->isAboutOrganization()) {
            return (int) $this->subject_id;
        }

        $id = $this->getProperty('organization_id');

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    /** The reason column, or a `reason` property written by older services. */
    public function why(): ?string
    {
        $reason = $this->reason ?? $this->getProperty('reason');

        return is_scalar($reason) && (string) $reason !== '' ? (string) $reason : null;
    }

    /** The support session the entry was written in, if any. */
    public function supportSessionId(): ?int
    {
        $id = $this->getProperty('impersonation_session_id') ?? ($this->log_name === 'impersonation' ? $this->getProperty('session_id') : null);

        return is_int($id) ? $id : null;
    }

    /**
     * What changed, field by field, as plain text: spatie's
     * `attribute_changes` (old → attributes) and the `old` → `new` /
     * `attributes` / `changes` shapes the services write into `properties`.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public function changes(): array
    {
        $rows = [];

        foreach ([$this->attribute_changes?->toArray() ?? [], $this->properties?->toArray() ?? []] as $source) {
            $hasOld = array_key_exists('old', $source);
            $newKey = array_key_exists('attributes', $source) ? 'attributes' : (array_key_exists('new', $source) ? 'new' : null);

            if ($hasOld || $newKey !== null) {
                $old = $hasOld ? $source['old'] : null;
                $new = $newKey !== null ? $source[$newKey] : null;

                if (is_array($old) || is_array($new)) {
                    $old = is_array($old) ? $old : [];
                    $new = is_array($new) ? $new : [];

                    foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
                        $rows[] = ['field' => (string) $field, 'old' => self::text($old[$field] ?? null), 'new' => self::text($new[$field] ?? null)];
                    }
                } else {
                    $rows[] = ['field' => 'value', 'old' => self::text($old), 'new' => self::text($new)];
                }
            }

            if (is_array($source['changes'] ?? null)) {
                foreach ($source['changes'] as $field => $change) {
                    $rows[] = is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change))
                        ? ['field' => (string) $field, 'old' => self::text($change['old'] ?? null), 'new' => self::text($change['new'] ?? null)]
                        : ['field' => (string) $field, 'old' => '', 'new' => self::text($change)];
                }
            }
        }

        return $rows;
    }

    /**
     * Every property, its value as text (JSON for lists and maps).
     *
     * @return array<string, string>
     */
    public function propertyLines(): array
    {
        $lines = [];

        foreach ($this->properties?->toArray() ?? [] as $key => $value) {
            $lines[(string) $key] = self::text($value);
        }

        return $lines;
    }

    /** A value as text, never markup (the page escapes it too). */
    public static function text(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        };

        return Str::limit($text, 2000);
    }
}
