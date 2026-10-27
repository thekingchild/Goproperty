<?php

declare(strict_types=1);

namespace Modules\Tenants\Profiles;

use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;

/**
 * The only writer of tenant profiles from the workspace pages
 * (DATABASE_SCHEMA.md § 1 `tenant_profiles`; the tenants import target is
 * the other writer): add, edit, archive (soft delete) and restore, each
 * checked against TenantProfilePolicy for the acting user (so the
 * suspension and impersonation guards apply) and written to the audit log
 * (log `tenants`, the tenant as subject). Audit entries name the fields
 * that changed, never the ID number.
 *
 * The phone number (E.164) is unique per organization, archived tenants
 * included (P02 decision 3): adding a tenant with an archived tenant's
 * number is refused with ArchivedTenantMatch, so the page offers to restore
 * that tenant instead; a number of an active tenant is refused with that
 * tenant's name.
 *
 * KYC: "being checked" and "verified" need an ID on file; changing the ID
 * of a checked tenant makes them "not verified" again, unless the same save
 * sets the status (as the import does, P02-06b). The ID number never goes
 * back to the browser: on an edit, an empty number keeps the one on file
 * while the ID type stays the same.
 */
final class TenantProfiles
{
    public const string LOG = 'tenants';

    /** Fields compared for the audit entry, by column. */
    private const array AUDITED = ['first_name', 'last_name', 'email', 'phone', 'address', 'occupation', 'emergency_contact', 'id_type', 'id_value', 'kyc_status'];

    /**
     * @param  array<string, mixed>  $input  firstName, lastName, phone, email, address, occupation, kinName, kinPhone, kinRelationship, idType, idValue, kycStatus
     *
     * @throws ArchivedTenantMatch when the phone number is an archived tenant's
     * @throws ValidationException
     */
    public function create(User $by, array $input): TenantProfile
    {
        Gate::forUser($by)->authorize('create', TenantProfile::class);

        $values = $this->validated($input, null);

        try {
            return DB::transaction(function () use ($by, $values): TenantProfile {
                $this->refuseTakenPhone($values['phone'], null);

                $tenant = new TenantProfile;
                $tenant->fill(Arr::except($values, 'kyc_status'));
                $tenant->kyc_status = $values['kyc_status'];
                $tenant->save();
                $this->audit('tenant.created', $tenant, $by, 'Tenant added');

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            // Someone added this number a moment ago.
            throw ValidationException::withMessages(['phone' => __('Another tenant has just been added with this phone number. Each tenant needs their own number.')]);
        }
    }

    /**
     * @param  array<string, mixed>  $input  as for create()
     *
     * @throws ValidationException
     */
    public function update(User $by, TenantProfile $tenant, array $input): TenantProfile
    {
        Gate::forUser($by)->authorize('update', $tenant);

        try {
            return DB::transaction(function () use ($by, $tenant, $input): TenantProfile {
                $tenant = TenantProfile::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
                $values = $this->validated($input, $tenant);

                if ($values['phone'] !== $tenant->phone) {
                    $this->refuseTakenPhone($values['phone'], $tenant);
                }

                $kycBefore = $tenant->kyc_status;
                $tenant->fill(Arr::except($values, 'kyc_status'));
                $tenant->kyc_status = $values['kyc_status'];

                if ($kycBefore !== KycStatus::Unverified && $values['kyc_status'] === $kycBefore && $tenant->isDirty(['id_type', 'id_value'])) {
                    // The check was of another document.
                    $tenant->kyc_status = KycStatus::Unverified;
                }

                $changed = array_values(array_intersect(self::AUDITED, array_keys($tenant->getDirty())));

                if ($changed !== []) {
                    $tenant->save();
                    $this->audit('tenant.updated', $tenant, $by, 'Tenant changed', ['changed' => $changed]
                        + ($kycBefore !== $tenant->kyc_status ? ['kyc_from' => $kycBefore->value, 'kyc_to' => $tenant->kyc_status->value] : []));
                }

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => __('Another tenant has just been given this phone number. Each tenant needs their own number.')]);
        }
    }

    /** Archive the tenant: a soft delete (AGENT.md § 3 rule 7). */
    public function archive(User $by, TenantProfile $tenant): void
    {
        Gate::forUser($by)->authorize('delete', $tenant);

        DB::transaction(function () use ($by, $tenant): void {
            $tenant = TenantProfile::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $tenant->delete();
            $this->audit('tenant.archived', $tenant, $by, 'Tenant archived');
        });
    }

    public function restore(User $by, TenantProfile $tenant): void
    {
        Gate::forUser($by)->authorize('restore', $tenant);

        DB::transaction(function () use ($by, $tenant): void {
            $tenant = TenantProfile::query()->onlyTrashed()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            $tenant->restore();
            $this->audit('tenant.restored', $tenant, $by, 'Tenant restored');
        });
    }

    /**
     * @throws ValidationException naming the tenant who has the number
     */
    private function refuseTakenPhone(string $phone, ?TenantProfile $except): void
    {
        $holder = TenantProfile::query()->withTrashed()->where('phone', $phone)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except?->getKey()))
            ->first();

        if ($holder === null) {
            return;
        }

        if ($holder->trashed() && $except === null) {
            throw ArchivedTenantMatch::of($holder);
        }

        throw ValidationException::withMessages(['phone' => $holder->trashed()
            ? __('This phone number belongs to :name, an archived tenant. Each tenant needs their own number.', ['name' => $holder->fullName()])
            : __('This phone number belongs to :name. Each tenant needs their own number.', ['name' => $holder->fullName()])]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{first_name: string, last_name: string, email: string|null, phone: string, address: string|null, occupation: string|null, emergency_contact: array{name: string|null, phone: string|null, relationship: string|null}|null, id_type: TenantIdType, id_value?: string|null, kyc_status: KycStatus}
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?TenantProfile $existing): array
    {
        $text = static function (mixed $value): ?string {
            $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };

        $idTypeInput = $text($input['idType'] ?? null) ?? TenantIdType::None->value;
        $idType = TenantIdType::tryFrom($idTypeInput);
        $idInput = $text($input['idValue'] ?? null);
        $kyc = KycStatus::tryFrom($text($input['kycStatus'] ?? null) ?? KycStatus::Unverified->value);

        $values = [
            'firstName' => $text($input['firstName'] ?? null),
            'lastName' => $text($input['lastName'] ?? null),
            'phone' => $text($input['phone'] ?? null),
            'email' => ($email = $text($input['email'] ?? null)) !== null ? mb_strtolower($email) : null,
            'address' => $text($input['address'] ?? null),
            'occupation' => $text($input['occupation'] ?? null),
            'kinName' => $text($input['kinName'] ?? null),
            'kinPhone' => $text($input['kinPhone'] ?? null),
            'kinRelationship' => $text($input['kinRelationship'] ?? null),
            'idType' => $idTypeInput,
            'idValue' => $idType !== null && $idType !== TenantIdType::None && $idInput !== null ? TenantIdType::normalise($idInput) : null,
            'kycStatus' => $text($input['kycStatus'] ?? null) ?? KycStatus::Unverified->value,
        ];

        // An empty number on an edit keeps the one on file, unless the type changed.
        $keepsId = $existing !== null && $values['idValue'] === null && $idType !== null && $idType !== TenantIdType::None
            && $existing->id_type === $idType && $existing->id_value !== null;
        $hasId = $idType !== null && $idType !== TenantIdType::None;

        $phone = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && PhoneNumber::normalize($value) === null) {
                $fail(__('Enter a phone number such as 0803 123 4567, or one starting with + and the country code.'));
            }
        };

        Validator::make($values, [
            'firstName' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'lastName' => ['required', 'string', 'max:80', 'not_regex:/[<>]/'],
            'phone' => ['required', 'string', 'max:30', $phone],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'occupation' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'],
            'kinName' => ['nullable', 'string', 'max:120', 'not_regex:/[<>]/'],
            'kinPhone' => ['nullable', 'string', 'max:30', $phone],
            'kinRelationship' => ['nullable', 'string', 'max:60', 'not_regex:/[<>]/'],
            'idType' => ['required', Rule::in(TenantIdType::values())],
            'idValue' => [
                Rule::requiredIf($hasId && ! $keepsId),
                'nullable', 'string',
                static function (string $attribute, mixed $value, Closure $fail) use ($idType): void {
                    // Never the number itself in the message.
                    if (is_string($value) && $idType !== null && ! $idType->accepts($value)) {
                        $fail(__($idType->formatHint()));
                    }
                },
            ],
            'kycStatus' => ['required', Rule::in(KycStatus::values()), static function (string $attribute, mixed $value, Closure $fail) use ($hasId, $kyc): void {
                if ($kyc !== null && $kyc !== KycStatus::Unverified && ! $hasId) {
                    $fail(__('Add the tenant\'s ID before marking them ":status".', ['status' => __($kyc->label())]));
                }
            }],
        ], [
            'firstName.required' => __('Enter the tenant\'s first name.'),
            'lastName.required' => __('Enter the tenant\'s last name.'),
            'phone.required' => __('Enter the tenant\'s phone number. It is how GoProperty recognises them.'),
            'idValue.required' => __('Enter the ID number, or choose "None".'),
            '*.not_regex' => __('Use plain text only.'),
        ], [
            'firstName' => 'first name',
            'lastName' => 'last name',
            'kinName' => 'next of kin',
            'kinPhone' => 'next of kin\'s phone',
            'kinRelationship' => 'relationship',
            'idType' => 'ID type',
            'idValue' => 'ID number',
            'kycStatus' => 'KYC status',
        ])->validate();

        $kin = [
            'name' => $values['kinName'],
            'phone' => PhoneNumber::normalize($values['kinPhone']),
            'relationship' => $values['kinRelationship'],
        ];

        $columns = [
            'first_name' => (string) $values['firstName'],
            'last_name' => (string) $values['lastName'],
            'email' => $values['email'],
            'phone' => (string) PhoneNumber::normalize($values['phone']),
            'address' => $values['address'],
            'occupation' => $values['occupation'],
            'emergency_contact' => array_filter($kin) === [] ? null : $kin,
            'id_type' => $idType ?? TenantIdType::None,
            'kyc_status' => $kyc ?? KycStatus::Unverified,
        ];

        if (! $keepsId) {
            $columns['id_value'] = $values['idValue'];
        }

        return $columns;
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, TenantProfile $tenant, User $by, string $description, array $properties = []): void
    {
        activity(self::LOG)
            ->event($event)
            ->performedOn($tenant)
            ->causedBy($by)
            ->withProperties(['tenant' => $tenant->fullName(), ...$properties])
            ->log($description);
    }
}
