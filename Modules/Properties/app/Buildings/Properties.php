<?php

declare(strict_types=1);

namespace Modules\Properties\Buildings;

use App\Enums\ChecklistItem;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Support\NigerianStates;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Properties\Enums\PropertyStatus;
use Modules\Properties\Enums\PropertyType;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;

/**
 * The only writer of buildings' details (DATABASE_SCHEMA.md § 3): add,
 * edit, archive and restore, each checked against PropertyPolicy for the
 * acting user (so the suspension and impersonation guards apply) and
 * written to the audit log (log `properties`, the building as subject).
 * Photos are PropertyPhotos'.
 *
 * Archiving changes `status` only: the row, its photos and (later) its
 * units and leases stay (AGENT.md § 3 rule 7), and an archived building's
 * units stop counting towards the GoProperty bill (BILLING_PAYMENTS.md
 * § 1.4). The slug is made from the name when the building is added, unique
 * in the organization (archived and soft-deleted buildings included), and
 * kept when the building is renamed so links keep working.
 */
final readonly class Properties
{
    public const string LOG = 'properties';

    /** Slugs a building may not take: they are paths of the buildings pages. */
    public const array RESERVED_SLUGS = ['create'];

    /** Fields compared for the audit entry, by column. */
    private const array AUDITED = ['owner_profile_id', 'name', 'property_type', 'address', 'state', 'lga', 'landmark', 'description', 'amenities', 'year_built'];

    public function __construct(private OnboardingChecklist $checklist) {}

    /**
     * @param  array<string, mixed>  $input  name, propertyType, ownerProfileId, address, state, lga, landmark, description, amenities (list), otherAmenities, yearBuilt
     *
     * @throws ValidationException
     */
    public function create(User $by, array $input): Property
    {
        Gate::forUser($by)->authorize('create', Property::class);

        $values = $this->validated($input, null);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($by, $values): Property {
                    $property = new Property($values);
                    $property->slug = $this->freeSlug($property->name);
                    $property->save();

                    $this->audit('property.created', $property, $by, 'Building added', ['slug' => $property->slug]);
                    // ONBOARDING_COMPLIANCE.md § 2 step 6: "or add your first property".
                    $this->checklist->complete(ChecklistItem::Data, $by, ['first_property_id' => $property->id]);

                    return $property;
                });
            } catch (UniqueConstraintViolationException $taken) {
                // Someone took the same slug a moment ago: pick the next one.
                if ($attempt >= 3) {
                    throw $taken;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input  as for create()
     *
     * @throws ValidationException
     */
    public function update(User $by, Property $property, array $input): Property
    {
        Gate::forUser($by)->authorize('update', $property);

        $values = $this->validated($input, $property);

        return DB::transaction(function () use ($by, $property, $values): Property {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();
            $property->fill($values);
            $changed = array_values(array_intersect(self::AUDITED, array_keys($property->getDirty())));

            if ($changed !== []) {
                $property->save();
                $this->audit('property.updated', $property, $by, 'Building changed', ['changed' => $changed]);
            }

            return $property;
        });
    }

    public function archive(User $by, Property $property): Property
    {
        Gate::forUser($by)->authorize('delete', $property);

        return $this->changeStatus($by, $property, PropertyStatus::Archived, 'property.archived', 'Building archived');
    }

    public function restore(User $by, Property $property): Property
    {
        Gate::forUser($by)->authorize('restore', $property);

        return $this->changeStatus($by, $property, PropertyStatus::Active, 'property.restored', 'Building restored');
    }

    /** The slug the name gives, or the next free one ("palm-court-2"). */
    public function freeSlug(string $name): string
    {
        $base = trim(Str::limit(Str::slug($name), 80, ''), '-');
        $base = $base !== '' ? $base : 'building';

        $taken = array_flip(array_merge(self::RESERVED_SLUGS, Property::query()->withTrashed()
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $base).'-%'))
            ->pluck('slug')
            ->all()));

        for ($n = 1; ; $n++) {
            $slug = $n === 1 ? $base : "{$base}-{$n}";

            if (! array_key_exists($slug, $taken)) {
                return $slug;
            }
        }
    }

    private function changeStatus(User $by, Property $property, PropertyStatus $status, string $event, string $description): Property
    {
        return DB::transaction(function () use ($by, $property, $status, $event, $description): Property {
            $property = Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            if ($property->status !== $status) {
                $property->status = $status;
                $property->save();
                $this->audit($event, $property, $by, $description);
            }

            return $property;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> column => value
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?Property $existing): array
    {
        $text = static function (mixed $value): ?string {
            $value = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

            return $value === '' ? null : $value;
        };

        $ownerInput = $input['ownerProfileId'] ?? null;
        $ownerId = is_int($ownerInput) || (is_string($ownerInput) && ctype_digit($ownerInput)) ? (int) $ownerInput : null;
        $yearInput = $input['yearBuilt'] ?? null;
        $ticked = is_array($input['amenities'] ?? null) ? $input['amenities'] : [];
        $other = is_string($input['otherAmenities'] ?? null) ? $input['otherAmenities'] : '';
        $amenities = Amenities::normalize($ticked, $other);

        $values = [
            'name' => $text($input['name'] ?? null),
            'propertyType' => $text($input['propertyType'] ?? null),
            'ownerProfileId' => $ownerInput === null || $ownerInput === '' ? null : $ownerInput,
            'address' => $text($input['address'] ?? null),
            'state' => $text($input['state'] ?? null),
            'lga' => $text($input['lga'] ?? null),
            'landmark' => $text($input['landmark'] ?? null),
            'description' => is_string($input['description'] ?? null) && trim($input['description']) !== '' ? trim($input['description']) : null,
            'amenities' => $amenities,
            'yearBuilt' => $yearInput === null || $yearInput === '' ? null : $yearInput,
        ];

        $maxYear = (int) now()->format('Y') + 5;

        Validator::make($values, [
            'name' => ['required', 'string', 'min:2', 'max:160', 'not_regex:/[<>]/'],
            'propertyType' => ['required', Rule::in(PropertyType::values())],
            'ownerProfileId' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) use ($ownerId, $existing): void {
                $owner = $ownerId !== null ? OwnerProfile::query()->withTrashed()->find($ownerId) : null;

                if ($owner === null) {
                    $fail(__('Choose an owner from the list.'));
                } elseif ($owner->trashed() && $existing?->owner_profile_id !== $owner->id) {
                    $fail(__(':name is archived. Restore the owner first, or choose another one.', ['name' => $owner->display_name]));
                }
            }],
            'address' => ['required', 'string', 'max:300', 'not_regex:/[<>]/'],
            'state' => ['required', Rule::in(NigerianStates::ALL)],
            'lga' => ['nullable', 'string', 'max:64', 'not_regex:/[<>]/'],
            'landmark' => ['nullable', 'string', 'max:160', 'not_regex:/[<>]/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amenities' => ['array', 'max:'.Amenities::MAX_ITEMS],
            'amenities.*' => ['string', 'max:'.Amenities::MAX_LENGTH, 'not_regex:/[<>]/'],
            'yearBuilt' => ['nullable', 'integer', 'between:1800,'.$maxYear],
        ], [
            '*.not_regex' => __('Use plain text only.'),
            'amenities.*.not_regex' => __('Use plain text only.'),
            'amenities.max' => __('List at most :max amenities.', ['max' => Amenities::MAX_ITEMS]),
            'amenities.*.max' => __('Keep each amenity to :max characters.', ['max' => Amenities::MAX_LENGTH]),
            'yearBuilt.between' => __('Enter a year between 1800 and :max.', ['max' => $maxYear]),
        ], [
            'name' => 'building name',
            'propertyType' => 'building type',
            'ownerProfileId' => 'owner',
            'lga' => 'LGA',
            'yearBuilt' => 'year built',
        ])->validate();

        return [
            'name' => $values['name'],
            'property_type' => PropertyType::from((string) $values['propertyType']),
            'owner_profile_id' => $ownerId,
            'address' => $values['address'],
            'state' => $values['state'],
            'lga' => $values['lga'],
            'landmark' => $values['landmark'],
            'description' => $values['description'],
            'amenities' => $amenities === [] ? null : $amenities,
            'year_built' => $values['yearBuilt'] === null ? null : (int) $values['yearBuilt'],
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $event, Property $property, User $by, string $description, array $properties = []): void
    {
        activity(self::LOG)
            ->event($event)
            ->performedOn($property)
            ->causedBy($by)
            ->withProperties(['building' => $property->name, ...$properties])
            ->log($description);
    }
}
