<?php

declare(strict_types=1);

namespace Modules\Handovers\Handovers;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds the agency buildings are offered to (BILLING_PAYMENTS.md § 7.1,
 * § 7.7 rule 4; kept from P02-04's billing transfers): only organizations
 * already on GoProperty that manage buildings for others (type `agency` or
 * `hybrid`), named by their web address (`goproperty.ng/o/{slug}`, the
 * bare slug, or an old slug the organization was renamed from) or by the
 * email address of one of their owners (`org-owner`). An address that
 * owns several organizations is ambiguous: the web address is needed then.
 *
 * Every miss answers the same way (null), whatever the reason, and the
 * offer form is rate-limited, so the form reveals no more than a link to
 * the organization's workspace would. Organizations that are suspended or
 * closed, and landlords' own organizations, take no offers and are
 * reported the same way.
 */
final class HandoverTargets
{
    public const string NOT_FOUND = 'No agency on GoProperty matches that. Check the web address (it looks like goproperty.ng/o/their-agency) or use the email address of one of its owners.';

    private const string SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function find(string $input): ?Organization
    {
        $input = Str::lower(trim($input));

        if ($input === '' || mb_strlen($input) > 255) {
            return null;
        }

        $organization = str_contains($input, '@') && ! str_contains($input, '/')
            ? $this->byOwnerEmail($input)
            : $this->bySlug($this->slugFrom($input));

        return $organization !== null && self::takesHandovers($organization) ? $organization : null;
    }

    /** Whether the organization can be offered buildings to manage: an agency (or both), open for business. */
    public static function takesHandovers(Organization $organization): bool
    {
        return in_array($organization->type, [OrganizationType::Agency, OrganizationType::Hybrid], true)
            && ! $organization->status->blocksWrites()
            && ! $organization->trashed();
    }

    /** "https://goproperty.ng/o/acme-homes/buildings" → "acme-homes"; a bare slug stays as it is. */
    private function slugFrom(string $input): string
    {
        if (preg_match('#(?:^|/)o/([^/?\#]+)#', $input, $match) === 1) {
            return $match[1];
        }

        return trim($input, '/');
    }

    private function bySlug(string $slug): ?Organization
    {
        if (preg_match(self::SLUG, $slug) !== 1 || strlen($slug) > 80) {
            return null;
        }

        return Organization::query()->where('slug', $slug)->first()
            ?? Organization::query()->whereJsonContains('previous_slugs', $slug)->first();
    }

    private function byOwnerEmail(string $email): ?Organization
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return null;
        }

        // Organizations where the address holds `org-owner` and is a member
        // (as App\Tenancy\OrganizationOwners reads it, the other way round).
        $owned = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', OrganizationRole::Owner->value)
            ->whereNull('roles.organization_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->id)
            ->select('model_has_roles.organization_id');

        $memberOf = DB::table('organization_user')->where('user_id', $user->id)->select('organization_id');

        $organizations = Organization::query()->whereIn('id', $owned)->whereIn('id', $memberOf)->limit(2)->get();

        return $organizations->count() === 1 ? $organizations->first() : null;
    }
}
