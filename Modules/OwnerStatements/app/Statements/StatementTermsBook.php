<?php

declare(strict_types=1);

namespace Modules\OwnerStatements\Statements;

use App\Models\User;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\OwnerStatements\Models\OwnerStatementSettlement;
use Modules\OwnerStatements\Models\OwnerStatementTerms;
use Modules\Properties\Contracts\OwnerProfileDirectory;

/**
 * The only writer of owners' statement terms (P08-05; `owner_statement_terms`):
 * the commission (a percentage of rent collected, up to two decimals,
 * 0–100 %, or blank for "not set") and "utilities pass to the owner".
 * The per-owner "share tenant contact details" switch was removed in P03-16
 * (product owner 2026-10-29: owners always see their tenants' details); a
 * `shareTenantContacts` key still sent by an older caller is ignored.
 * Checks OwnerStatementPolicy::changeTerms
 * (owners and administrators, `owner_statements.manage`: never while
 * GoProperty staff impersonate, nothing in a suspended organization), and
 * audits every change under log `owner-statements`. Terms apply to the
 * months not yet settled; a settled month keeps the terms it was settled
 * with.
 */
final readonly class StatementTermsBook
{
    public const string LOG = 'owner-statements';

    public function __construct(
        private OrganizationContext $context,
        private OwnerProfileDirectory $owners,
    ) {}

    /** The owner's terms (the defaults when none were set). */
    public function of(int $ownerProfileId): StatementTerms
    {
        $row = OwnerStatementTerms::query()->where('owner_profile_id', $ownerProfileId)->first();

        return $row instanceof OwnerStatementTerms
            ? new StatementTerms($row->commission_bps, $row->utilities_to_owner)
            : new StatementTerms;
    }

    /**
     * @param  array<string, mixed>  $input  `commission` as a percentage ("10", "7.5") or blank, `utilitiesToOwner`; other keys are ignored
     *
     * @throws ValidationException
     */
    public function change(User $by, int $ownerProfileId, array $input): StatementTerms
    {
        Gate::forUser($by)->authorize('changeTerms', [OwnerStatementSettlement::class, $ownerProfileId]);

        $owner = $this->owners->find($ownerProfileId);
        abort_if($owner === null, 404);

        $commission = trim(is_scalar($input['commission'] ?? null) ? (string) $input['commission'] : '');
        $commission = rtrim(str_replace([' ', '%'], '', $commission));

        Validator::make(['commission' => $commission], [
            'commission' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && $value !== '' && (float) $value > 100) {
                    $fail(__('The commission cannot be more than 100%.'));
                }
            }],
        ], [
            'commission.regex' => __('Type the commission as a percentage, such as 10 or 7.5.'),
        ])->validate();

        $terms = new StatementTerms(
            commissionBps: $commission === '' ? null : self::basisPoints($commission),
            utilitiesToOwner: filter_var($input['utilitiesToOwner'] ?? false, FILTER_VALIDATE_BOOL),
        );

        return DB::transaction(function () use ($by, $ownerProfileId, $terms): StatementTerms {
            $row = $this->locked($ownerProfileId);
            $before = ['commission_bps' => $row->commission_bps, 'utilities_to_owner' => $row->utilities_to_owner];

            $row->commission_bps = $terms->commissionBps;
            $row->utilities_to_owner = $terms->utilitiesToOwner;

            if (! $row->isDirty()) {
                return $terms;
            }

            $row->updated_by_user_id = $by->id;
            $row->save();

            activity(self::LOG)
                ->event('owner_statement.terms_changed')
                ->performedOn($row)
                ->causedBy($by)
                ->withProperties([
                    'owner_profile_id' => $ownerProfileId,
                    'before' => $before,
                    'after' => ['commission_bps' => $terms->commissionBps, 'utilities_to_owner' => $terms->utilitiesToOwner],
                ])
                ->log('Owner statement terms changed');

            return $terms;
        }, 3);
    }

    /** "7.5" → 750 (whole basis points, never through a float). */
    private static function basisPoints(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /** The owner's row, locked until the transaction ends (made on first use). */
    private function locked(int $ownerProfileId): OwnerStatementTerms
    {
        $row = OwnerStatementTerms::query()->where('owner_profile_id', $ownerProfileId)->lockForUpdate()->first();

        if ($row instanceof OwnerStatementTerms) {
            return $row;
        }

        try {
            DB::transaction(function () use ($ownerProfileId): void {
                $row = new OwnerStatementTerms;
                $row->forceFill(['organization_id' => $this->context->id(), 'owner_profile_id' => $ownerProfileId])->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Made by another request at the same time: take that one.
        }

        return OwnerStatementTerms::query()->where('owner_profile_id', $ownerProfileId)->lockForUpdate()->firstOrFail();
    }
}
