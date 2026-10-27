<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Enums\TransactionTier;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OrganizationSlug;
use App\Support\NigerianStates;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Profile: the organization's name, web address (/o/{slug}),
 * address, state, LGA and CAC number. The only writer of these columns
 * after signup.
 *
 * - A new web address must be free (OrganizationSlug); the old one is kept
 *   in `previous_slugs`, so links and bookmarks keep working
 *   (SetOrganizationContext redirects members). An organization may take
 *   back one of its own old addresses. At most PREVIOUS_SLUGS_MAX old
 *   addresses are kept, so nobody can reserve addresses by renaming.
 * - From Tier 2 the registered name and CAC number were checked by
 *   compliance (ONBOARDING_COMPLIANCE.md § 5.3; BRANDING_DOCUMENTS.md § 1:
 *   "Tier 2/3 must match CAC name"), so they are locked here; compliance
 *   changes them.
 *
 * Org-owners and administrators (`organization.update`). The organization's
 * activity log records each change (the CAC number only as "changed").
 */
final class OrganizationProfile
{
    public const int PREVIOUS_SLUGS_MAX = 10;

    /**
     * @param  array{name?: mixed, slug?: mixed, address?: mixed, state?: mixed, lga?: mixed, cacNumber?: mixed}  $input
     *
     * @throws ValidationException
     */
    public function update(User $by, Organization $organization, array $input): Organization
    {
        Gate::forUser($by)->authorize('updateProfile', $organization);

        $values = [
            'name' => trim((string) preg_replace('/\s+/', ' ', is_string($input['name'] ?? null) ? $input['name'] : '')),
            'slug' => strtolower(trim(is_string($input['slug'] ?? null) ? $input['slug'] : '')),
            'address' => self::text($input['address'] ?? null),
            'state' => is_string($input['state'] ?? null) ? $input['state'] : '',
            'lga' => self::text($input['lga'] ?? null),
            'cacNumber' => ($cac = self::text($input['cacNumber'] ?? null)) !== null ? strtoupper((string) preg_replace('/\s+/', ' ', $cac)) : null,
        ];

        Validator::make($values, $this->rules($organization), [
            'cacNumber.regex' => 'Enter the number as on your CAC certificate, such as RC 1234567.',
            '*.not_regex' => 'Use plain text only.',
        ], [
            'name' => 'organization name',
            'slug' => 'web address',
            'lga' => 'LGA',
            'cacNumber' => 'CAC number',
        ])->validate();

        return DB::transaction(function () use ($organization, $values): Organization {
            $organization = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            if ($values['slug'] !== $organization->slug) {
                $this->ensureSlugFree($organization, $values['slug']);
                $previous = array_values(array_filter($organization->previous_slugs ?? [], static fn (string $slug): bool => $slug !== $values['slug']));
                array_unshift($previous, $organization->slug);

                if (count($previous) > self::PREVIOUS_SLUGS_MAX) {
                    throw ValidationException::withMessages(['slug' => 'This organization has changed its web address too often. Contact support to change it again.']);
                }

                $organization->previous_slugs = $previous;
                $organization->slug = $values['slug'];
            }

            $organization->fill([
                'name' => $values['name'],
                'address' => $values['address'],
                'state' => $values['state'],
                'lga' => $values['lga'],
            ]);

            $cacChanged = $values['cacNumber'] !== $organization->cac_number;
            $organization->cac_number = $values['cacNumber'];
            $organization->save();

            if ($cacChanged) {
                activity('organization')->event('organization.cac_number_changed')->performedOn($organization)->log('CAC number changed');
            }

            return $organization;
        });
    }

    /** Whether the registered name and CAC number are locked (Tier 2 and 3). */
    public static function registrationLocked(Organization $organization): bool
    {
        return $organization->transaction_tier !== TransactionTier::One;
    }

    /** @return array<string, list<mixed>> */
    private function rules(Organization $organization): array
    {
        $locked = self::registrationLocked($organization);
        $unchanged = static fn (mixed $current, string $label): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($current, $label): void {
            if ($value !== $current) {
                $fail("Your organization is at a higher Transaction Tier, so its {$label} was checked by compliance. Contact support to change it.");
            }
        };

        return [
            'name' => ['required', 'string', 'min:2', 'max:120', 'not_regex:/[<>]/', ...($locked ? [$unchanged($organization->name, 'registered name')] : [])],
            'slug' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! OrganizationSlug::isWellFormed($value)) {
                    $fail('Use '.OrganizationSlug::MIN.' to '.OrganizationSlug::MAX.' lower-case letters, digits and single hyphens. Some words are reserved.');
                }
            }],
            'address' => ['nullable', 'string', 'max:300', 'not_regex:/[<>]/'],
            'state' => ['required', Rule::in(NigerianStates::ALL)],
            'lga' => ['nullable', 'string', 'max:64', 'not_regex:/[<>]/'],
            'cacNumber' => ['nullable', 'string', 'max:20', 'regex:/^(RC|BN|IT|LP|LLP)?\s?\d{1,9}$/i', ...($locked ? [$unchanged($organization->cac_number, 'CAC number')] : [])],
        ];
    }

    /** @throws ValidationException */
    private function ensureSlugFree(Organization $organization, string $slug): void
    {
        $ownOld = in_array($slug, $organization->previous_slugs ?? [], true);
        $takenByOther = Organization::query()->withTrashed()->whereKeyNot($organization->getKey())
            ->where(fn ($query) => $query->where('slug', $slug)->orWhereJsonContains('previous_slugs', $slug))
            ->exists();

        if ($takenByOther || (! $ownOld && ! app(OrganizationSlug::class)->isAvailable($slug))) {
            throw ValidationException::withMessages(['slug' => 'This web address is taken. Try another.']);
        }
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
