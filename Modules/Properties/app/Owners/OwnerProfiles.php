<?php

declare(strict_types=1);

namespace Modules\Properties\Owners;

use App\Models\User;
use App\Support\NigerianStates;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Models\OwnerProfile;

/**
 * The only writer of owner profiles (DATABASE_SCHEMA.md § 1): add, edit,
 * archive (soft delete) and restore, each checked against
 * OwnerProfilePolicy for the acting user (so the suspension and
 * impersonation guards apply) and written to the audit log (log `owners`,
 * the owner as subject). Audit entries name the fields that changed, never
 * the ID number itself.
 *
 * The ID number never goes back to the browser: on an edit, an empty number
 * keeps the one on file while the ID type stays the same.
 */
final class OwnerProfiles
{
    public const string LOG = 'owners';

    /** Fields compared for the audit entry, by column. */
    private const array AUDITED = ['type', 'first_name', 'last_name', 'company_name', 'email', 'phone', 'address', 'state', 'lga', 'id_type', 'id_value', 'notes'];

    /**
     * @param  array<string, mixed>  $input  type, firstName, lastName, companyName, email, phone, address, state, lga, idType, idValue, notes
     *
     * @throws ValidationException
     */
    public function create(User $by, array $input): OwnerProfile
    {
        Gate::forUser($by)->authorize('create', OwnerProfile::class);

        $values = $this->validated($input, null);

        return DB::transaction(function () use ($by, $values): OwnerProfile {
            $owner = OwnerProfile::query()->create($values);
            $this->audit('owner.created', $owner, $by, 'Owner added');

            return $owner;
        });
    }

    /**
     * @param  array<string, mixed>  $input  as for create()
     *
     * @throws ValidationException
     */
    public function update(User $by, OwnerProfile $owner, array $input): OwnerProfile
    {
        Gate::forUser($by)->authorize('update', $owner);

        $values = $this->validated($input, $owner);

        return DB::transaction(function () use ($by, $owner, $values): OwnerProfile {
            $owner = OwnerProfile::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $owner->fill($values);
            $changed = array_values(array_intersect(self::AUDITED, array_keys($owner->getDirty())));

            if ($changed !== []) {
                $owner->save();
                $this->audit('owner.updated', $owner, $by, 'Owner changed', ['changed' => $changed]);
            }

            return $owner;
        });
    }

    public function archive(User $by, OwnerProfile $owner): void
    {
        Gate::forUser($by)->authorize('delete', $owner);

        $owner->delete();
        $this->audit('owner.archived', $owner, $by, 'Owner archived');
    }

    public function restore(User $by, OwnerProfile $owner): void
    {
        Gate::forUser($by)->authorize('restore', $owner);

        $owner->restore();
        $this->audit('owner.restored', $owner, $by, 'Owner restored');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> column => value
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?OwnerProfile $existing): array
    {
        $text = static function (mixed $value): ?string {
            $value = is_string($value) ? trim((string) preg_replace('/\s+/', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };

        $type = OwnerType::tryFrom(is_string($input['type'] ?? null) ? $input['type'] : '');
        $idType = OwnerIdType::tryFrom(is_string($input['idType'] ?? null) && $input['idType'] !== '' ? $input['idType'] : OwnerIdType::None->value);
        $phoneInput = $text($input['phone'] ?? null);
        $idInput = $text($input['idValue'] ?? null);

        $values = [
            'type' => $type?->value,
            'firstName' => $type === OwnerType::Person ? $text($input['firstName'] ?? null) : null,
            'lastName' => $type === OwnerType::Person ? $text($input['lastName'] ?? null) : null,
            'companyName' => $type === OwnerType::Company ? $text($input['companyName'] ?? null) : null,
            'email' => ($email = $text($input['email'] ?? null)) !== null ? strtolower($email) : null,
            'phone' => $phoneInput,
            'address' => $text($input['address'] ?? null),
            'state' => $text($input['state'] ?? null),
            'lga' => $text($input['lga'] ?? null),
            'idType' => is_string($input['idType'] ?? null) && $input['idType'] !== '' ? $input['idType'] : OwnerIdType::None->value,
            'idValue' => match ($idType) {
                OwnerIdType::Nin => $idInput !== null ? (string) preg_replace('/\s+/', '', $idInput) : null,
                OwnerIdType::Cac => $idInput !== null ? strtoupper($idInput) : null,
                default => null,
            },
            'notes' => is_string($input['notes'] ?? null) && trim($input['notes']) !== '' ? trim($input['notes']) : null,
        ];

        // An empty number on an edit keeps the one on file, unless the type changed.
        $keepsId = $existing !== null && $values['idValue'] === null && $idType !== null && $idType !== OwnerIdType::None
            && $existing->id_type === $idType && $existing->id_value !== null;

        Validator::make($values, [
            'type' => ['required', Rule::in(OwnerType::values())],
            'firstName' => [Rule::requiredIf($type === OwnerType::Person), 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'lastName' => [Rule::requiredIf($type === OwnerType::Person), 'nullable', 'string', 'max:80', 'not_regex:/[<>]/'],
            'companyName' => [Rule::requiredIf($type === OwnerType::Company), 'nullable', 'string', 'min:2', 'max:160', 'not_regex:/[<>]/'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && PhoneNumber::normalize($value) === null) {
                    $fail('Enter a phone number such as 0803 123 4567, or one starting with + and the country code.');
                }
            }],
            'address' => ['nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'state' => ['nullable', Rule::in(NigerianStates::ALL)],
            'lga' => ['nullable', 'string', 'max:64', 'not_regex:/[<>]/'],
            'idType' => ['required', Rule::in(OwnerIdType::values())],
            'idValue' => [
                Rule::requiredIf($idType !== null && $idType !== OwnerIdType::None && ! $keepsId),
                'nullable', 'string',
                ...(($pattern = $idType?->pattern()) !== null ? ['regex:'.$pattern] : []),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'idValue.required' => $idType === OwnerIdType::Cac ? 'Enter the CAC number, or choose "None".' : 'Enter the NIN, or choose "None".',
            'idValue.regex' => $idType === OwnerIdType::Cac ? 'Enter the number as on the CAC certificate, such as RC 1234567.' : 'A NIN is 11 digits.',
            '*.not_regex' => 'Use plain text only.',
        ], [
            'type' => 'owner type',
            'firstName' => 'first name',
            'lastName' => 'last name',
            'companyName' => 'company name',
            'lga' => 'LGA',
            'idType' => 'ID type',
            'idValue' => 'ID number',
        ])->validate();

        $columns = [
            'type' => $type,
            'first_name' => $values['firstName'],
            'last_name' => $values['lastName'],
            'company_name' => $values['companyName'],
            'email' => $values['email'],
            'phone' => $phoneInput !== null ? PhoneNumber::normalize($phoneInput) : null,
            'address' => $values['address'],
            'state' => $values['state'],
            'lga' => $values['lga'],
            'id_type' => $idType ?? OwnerIdType::None,
            'notes' => $values['notes'],
        ];

        if (! $keepsId) {
            $columns['id_value'] = $values['idValue'];
        }

        return $columns;
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, OwnerProfile $owner, User $by, string $description, array $properties = []): void
    {
        activity(self::LOG)
            ->event($event)
            ->performedOn($owner)
            ->causedBy($by)
            ->withProperties(['owner' => $owner->display_name, ...$properties])
            ->log($description);
    }
}
