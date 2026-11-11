<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every table that carries `organization_id`, and how the NDPA tooling
 * treats it (P09-02; ARCHITECTURE.md § 10 "How the NDPA tooling is
 * built"): which rows are a tenant's, what an erasure changes in them,
 * what the organization's export leaves out, which columns are masked or
 * never exported, and what an organization's purge keeps.
 *
 * This is the NDPA erasure tooling AGENT.md § 3 rule 7 reserves purge
 * paths for. Like the console's read models (ARCHITECTURE.md § 4) it reads
 * the modules' tables by name, always limited to one organization, rather
 * than through each module's models, so one place can be checked against
 * the whole schema: tests/Feature/Privacy/PersonalDataMapTest.php fails
 * for a table with `organization_id` (or an organization-scoped model)
 * that is not listed here, and for an encrypted column not masked.
 *
 * Rules run in this order, so a rule may use the rows found before it
 * (PersonSelection::ids()). A tenant's rows are found inside their own
 * organization only: the same person renting elsewhere is untouched.
 */
final class PersonalDataMap
{
    /** What replaces text the person wrote themselves (default 15). */
    public const string REMOVED = '[Removed at the person\'s request]';

    /** @var array<string, TableRule>|null */
    private ?array $rules = null;

    /** @return array<string, TableRule> */
    public function rules(): array
    {
        return $this->rules ??= $this->build();
    }

    public function rule(string $table): ?TableRule
    {
        return $this->rules()[$table] ?? null;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->rules());
    }

    /** @return array<string, TableRule> */
    private function build(): array
    {
        $tenant = static fn (string $column = 'tenant_profile_id'): Closure => static fn (Builder $query, Person $person): Builder => $query->where($column, $person->tenantProfileId);
        $in = static fn (string $column, string $table): Closure => static fn (Builder $query, Person $person, PersonSelection $selected): Builder => $query->whereIn($column, $selected->ids($table));
        $account = static fn (string $column): Closure => static fn (Builder $query, Person $person): Builder => $person->userId === null ? $query->whereRaw('1 = 0') : $query->where($column, $person->userId);
        $any = static fn (Closure ...$rules): Closure => static fn (Builder $query, Person $person, PersonSelection $selected): Builder => $query->where(static function (Builder $where) use ($rules, $person, $selected): void {
            foreach ($rules as $rule) {
                $where->orWhere(static fn (Builder $one): mixed => $rule($one, $person, $selected));
            }
        });
        $now = static fn (): string => now()->toDateTimeString();

        return [
            // -----------------------------------------------------------------
            // The tenant's own rows (in the order the rules build on each other)
            // -----------------------------------------------------------------

            'tenant_profiles' => TableRule::person(
                $tenant('id'),
                static fn (Eraser $e): Eraser => $e->set([
                    'first_name' => 'Erased',
                    'last_name' => 'tenant',
                    'email' => null,
                    // Unique per organization, never a number anyone can reach.
                    'phone' => static fn (int $id): string => 'erased-'.$id,
                    'address' => null,
                    'occupation' => null,
                    'emergency_contact' => null,
                    'id_value' => null,
                    'external_ref' => null,
                    'user_id' => null,
                    'updated_at' => $now(),
                ])->set(['deleted_at' => $now()], ['deleted_at' => null]),
            )->masked('id_value'),

            'leases' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->set(['notes' => null, 'external_ref' => null])->scrub('termination_reason'),
            ),

            'lease_deposit_entries' => TableRule::person(
                $in('lease_id', 'leases'),
                static fn (Eraser $e): Eraser => $e->scrub('note'),
            ),

            'late_fees' => TableRule::notPersonal('Late fees charged on rent bills (PL-02): bill ids, the rate and amounts.'),
            'lease_contracts' => TableRule::person(
                $in('lease_id', 'leases'),
                static fn (Eraser $e): Eraser => $e->scrubJson('details')->scrub('clauses')->set(['document_media_id' => null]),
            ),

            'bills' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->scrubJson('details')->scrub('description', 'void_reason')->set(['document_media_id' => null, 'external_ref' => null]),
            ),

            'bill_items' => TableRule::person(
                $in('bill_id', 'bills'),
                static fn (Eraser $e): Eraser => $e->scrub('label', 'note')->scrubJson('meta'),
            ),

            'payments' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->set(['payer_name' => null, 'note' => null, 'external_ref' => null])->scrub('confirmation_note', 'rejection_reason'),
            ),

            'payment_allocations' => TableRule::person(
                $any($in('payment_id', 'payments'), $in('bill_id', 'bills')),
                static fn (Eraser $e): Eraser => $e,
            ),

            'receipts' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->scrubJson('details')->scrub('void_request_reason', 'void_reason')->set(['document_media_id' => null, 'void_document_media_id' => null]),
            ),

            'payment_proofs' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->set(['sender_name' => Scrubber::ERASED, 'note' => null])->scrub('archive_reason'),
            ),

            'payment_notices' => TableRule::person(
                $in('payment_id', 'payments'),
                static fn (Eraser $e): Eraser => $e,
            ),

            'paystack_transactions' => TableRule::person(
                $any($tenant(), $in('bill_id', 'bills')),
                static fn (Eraser $e): Eraser => $e,
            ),

            // The organization's Paystack's messages about the person's payments.
            'webhook_log' => TableRule::person(
                static fn (Builder $query, Person $person, PersonSelection $selected): Builder => $query->whereIn(
                    'reference',
                    DB::table('paystack_transactions')->whereIn('id', $selected->ids('paystack_transactions'))->pluck('reference')->all(),
                ),
                static fn (Eraser $e): Eraser => $e->scrubJson('payload'),
            )->omitted('signature_valid', 'ip_address'),

            'bank_statement_lines' => TableRule::person(
                $any($tenant(), $in('payment_id', 'payments')),
                static fn (Eraser $e): Eraser => $e->scrub('narration', 'counterparty_name'),
            ),

            'tenant_credit_entries' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->scrub('note')->set(['external_ref' => null]),
            ),

            'tenant_credit_balances' => TableRule::person($tenant(), static fn (Eraser $e): Eraser => $e),

            'opening_balances' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->scrub('description')->set(['external_ref' => null]),
            ),

            'tickets' => TableRule::person(
                $any($tenant(), $account('raised_by_user_id')),
                static fn (Eraser $e): Eraser => $e->scrub('title', 'description'),
            ),

            // Their tickets' thread as the portal shows it (internal notes are the
            // agency's own working notes; default 9), and anything they wrote.
            'ticket_messages' => TableRule::person(
                $any(
                    static fn (Builder $query, Person $person, PersonSelection $selected): Builder => $query->whereIn('ticket_id', $selected->ids('tickets'))->where('is_internal', false),
                    $account('author_user_id'),
                ),
                static fn (Eraser $e): Eraser => $e
                    ->set(['body' => self::REMOVED], $e->person->userId !== null ? ['author_user_id' => $e->person->userId] : ['id' => 0])
                    ->scrub('body'),
                // The internal notes on their tickets lose their name too.
            )->alsoErased($in('ticket_id', 'tickets')),

            'ticket_status_history' => TableRule::person(
                $in('ticket_id', 'tickets'),
                static fn (Eraser $e): Eraser => $e->scrub('note'),
            ),

            'ticket_notices' => TableRule::person($account('user_id'), static fn (Eraser $e): Eraser => $e),

            'group_members' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->delete(),
            ),

            'group_posts' => TableRule::person(
                $tenant('author_tenant_profile_id'),
                static fn (Eraser $e): Eraser => $e->set(['body' => self::REMOVED]),
            ),

            'group_comments' => TableRule::person(
                $tenant('author_tenant_profile_id'),
                static fn (Eraser $e): Eraser => $e->set(['body' => self::REMOVED]),
            ),

            'group_reactions' => TableRule::person(
                $account('user_id'),
                static fn (Eraser $e): Eraser => $e->delete(),
            ),

            'group_reports' => TableRule::person(
                $tenant('reporter_tenant_profile_id'),
                static fn (Eraser $e): Eraser => $e->set(['note' => null]),
            ),

            'notification_deliveries' => TableRule::person(
                static fn (Builder $query, Person $person): Builder => $query->where(static function (Builder $where) use ($person): void {
                    if ($person->userId !== null) {
                        $where->orWhere('recipient_user_id', $person->userId);
                    }

                    $contacts = [...$person->emails, ...$person->phones];
                    $contacts === [] ? $where->orWhereRaw('1 = 0') : $where->orWhereIn('recipient_contact', $contacts);
                }),
                static fn (Eraser $e): Eraser => $e->set(['recipient_contact' => 'erased', 'body' => Scrubber::ERASED])->scrub('subject', 'error'),
            ),

            'reminders' => TableRule::person(
                $any($tenant('recipient_tenant_profile_id'), $account('recipient_user_id')),
                static fn (Eraser $e): Eraser => $e->set(['recipient_email' => null, 'recipient_phone' => null]),
            ),

            'whatsapp_opt_ins' => TableRule::person(
                $tenant(),
                // The times stay as the proof of the opt-in and opt-out.
                static fn (Eraser $e): Eraser => $e->set(['phone' => 'erased']),
            ),

            'invitations' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->set(['email' => null, 'phone' => null]),
            )->omitted('token_hash'),

            'management_handover_tenants' => TableRule::person(
                $tenant(),
                static fn (Eraser $e): Eraser => $e->set(['tenant_name' => 'Erased tenant']),
            ),

            // Their agreement to GoProperty's terms when they accepted this
            // organization's invitation. Kept as the proof; the IP address and
            // browser go (default 17).
            'consent_records' => TableRule::person(
                $account('user_id'),
                static fn (Eraser $e): Eraser => $e->set(['ip_address' => null, 'user_agent' => null]),
            )->notInOrganizationExport('GoProperty\'s own record of each person\'s agreement to its terms; in the person\'s own export instead.')
                ->keptOnPurge('Proof of consent (NDPA 2023 s. 26); the IP address and browser are blanked.'),

            // Files of the rows above: the record's own files (the media
            // library's owner) and the files it points at (`*media_id`).
            'media' => TableRule::person(
                static fn (Builder $query, Person $person, PersonSelection $selected): Builder => self::filesOf($query, $person, $selected),
                // The files themselves are purged by the erasure, inside Media::purging().
                static fn (Eraser $e): Eraser => $e,
            ),

            // -----------------------------------------------------------------
            // Not about one tenant
            // -----------------------------------------------------------------

            'organization_user' => TableRule::notPersonal('Workspace members: tenants are never members of the organization (ONBOARDING_COMPLIANCE.md § 2).'),
            'roles' => TableRule::notPersonal('The organization\'s roles.'),
            'model_has_roles' => TableRule::notPersonal('Members\' roles in the workspace; tenants hold none.'),
            'model_has_permissions' => TableRule::notPersonal('Members\' permissions in the workspace; tenants hold none.'),
            'organization_onboardings' => TableRule::notPersonal('The organization\'s signup.'),
            'onboarding_checklist_items' => TableRule::notPersonal('The organization\'s setup checklist.'),
            'organization_bank_accounts' => TableRule::notPersonal('The organization\'s own bank accounts.')
                ->masked('account_number')->omitted('account_fingerprint'),
            'organization_paystack_connections' => TableRule::notPersonal('The organization\'s own Paystack account.')
                ->masked('secret_key')->omitted('webhook_token', 'secret_key_last4'),
            'organization_whatsapp_connections' => TableRule::notPersonal('The organization\'s own WhatsApp Business account.')
                ->masked('access_token', 'app_secret')->omitted('webhook_ulid', 'webhook_verify_token'),
            'whatsapp_templates' => TableRule::notPersonal('The organization\'s message templates.'),
            'notification_channel_mappings' => TableRule::notPersonal('Which channel each kind of message uses.'),
            'organization_feature_overrides' => TableRule::notPersonal('The organization\'s plan features.'),
            'organization_tier_overrides' => TableRule::notPersonal('The organization\'s Transaction Tier limits.'),
            'organization_volume_snapshots' => TableRule::notPersonal('The organization\'s collections measured against its tier.'),
            'tier_applications' => TableRule::notPersonal('The organization\'s Transaction Tier applications.')
                ->masked('compliance_contact'),
            'compliance_documents' => TableRule::notPersonal('The organization\'s compliance documents (CAC, SCUML, directors).')
                ->masked('original_name', 'document_number', 'holder_name')->omitted('disk', 'path'),
            'compliance_flags' => TableRule::notPersonal('GoProperty\'s anti-money-laundering monitoring: never disclosed to the person it is about (no tipping-off; default 10).')
                ->notInOrganizationExport('GoProperty\'s own anti-money-laundering monitoring (default 10).'),
            'impersonation_sessions' => TableRule::notPersonal('GoProperty support sessions (PLATFORM_CONSOLE.md § 6).')
                ->notInOrganizationExport('GoProperty\'s own record of its support sessions (PLATFORM_CONSOLE.md § 6).')
                ->keptOnPurge('GoProperty\'s audit trail of support access, kept for the audit-log retention (PLATFORM_CONSOLE.md § 6–7).')
                ->omitted('handoff_token_hash', 'ip', 'user_agent'),
            'owner_profiles' => TableRule::notPersonal('Property owners, not tenants.')->masked('id_value'),
            'owner_statement_terms' => TableRule::notPersonal('Owners\' statement terms.'),
            'owner_statement_settlements' => TableRule::notPersonal('Owners\' monthly statements.'),
            'properties' => TableRule::notPersonal('The organization\'s buildings; the tenant\'s leases name theirs.'),
            'units' => TableRule::notPersonal('The organization\'s units; the tenant\'s leases name theirs.'),
            'agent_building' => TableRule::notPersonal('Which agents work in which building.'),
            'building_groups' => TableRule::notPersonal('The buildings\' groups; the tenant\'s membership, posts and comments are exported.'),
            'group_moderation_events' => TableRule::notPersonal('The moderators\' own trail of actions.'),
            'utility_types' => TableRule::notPersonal('The organization\'s utilities.'),
            'unit_utility_settings' => TableRule::notPersonal('Which units are charged which utilities.'),
            'utility_readings' => TableRule::notPersonal('Meter readings of a unit, whoever lives there; the tenant\'s bills carry what they were charged.'),
            'bank_statement_imports' => TableRule::notPersonal('The organization\'s bank statement files; the lines matched to the tenant are exported.'),
            'bank_statement_column_mappings' => TableRule::notPersonal('How the organization\'s statements are read.'),
            'expenses' => TableRule::notPersonal('The organization\'s own bookkeeping.'),
            'expense_receipts' => TableRule::notPersonal('The organization\'s expense receipts.'),
            'import_batches' => TableRule::notPersonal('Spreadsheet imports (the files are deleted after 30 days).'),
            'import_column_mappings' => TableRule::notPersonal('How the organization\'s spreadsheets are read.'),
            'management_handovers' => TableRule::notPersonal('Buildings handed between organizations; the tenant\'s own line is exported.'),
            'management_handover_buildings' => TableRule::notPersonal('Buildings handed between organizations.'),
            'document_counters' => TableRule::notPersonal('Document numbering.'),
            'document_verifications' => TableRule::notPersonal('Verification codes printed on documents.'),
            'sms_credit_balances' => TableRule::notPersonal('The organization\'s SMS credits.'),
            'sms_credit_entries' => TableRule::notPersonal('The organization\'s SMS credit ledger.'),
            'subscriptions' => TableRule::notPersonal('The organization\'s GoProperty subscription.')
                ->keptOnPurge('GoProperty\'s own billing records (tax and accounting law; default 22).'),
            'subscription_unit_events' => TableRule::notPersonal('Units the subscription billed.')
                ->keptOnPurge('GoProperty\'s own billing records (default 22).'),
            'platform_invoices' => TableRule::notPersonal('GoProperty\'s invoices to the organization.')
                ->keptOnPurge('GoProperty\'s own invoices (tax and accounting law; default 22).'),
            'platform_payments' => TableRule::notPersonal('Payments to GoProperty.')
                ->keptOnPurge('GoProperty\'s own income (default 22).'),
            'platform_refunds' => TableRule::notPersonal('Refunds by GoProperty.')
                ->keptOnPurge('GoProperty\'s own refunds (default 22).'),
            'dunning_notices' => TableRule::notPersonal('Reminders of unpaid GoProperty invoices.'),
            'plan_change_notices' => TableRule::notPersonal('Notices of plan changes.'),
            'data_requests' => TableRule::notPersonal('The register of data protection requests itself.')
                ->notInOrganizationExport('The register of data protection requests itself.')
                ->keptOnPurge('The record that the request was carried out; it holds no personal data.'),
        ];
    }

    /** The files of the person's rows: owned by them (media library) or pointed at by a `*media_id` column. */
    private static function filesOf(Builder $query, Person $person, PersonSelection $selected): Builder
    {
        $byColumn = [];
        $owners = [];

        foreach ($selected->all() as $table => $ids) {
            if ($ids === [] || $table === 'media') {
                continue;
            }

            foreach (self::mediaColumns($table) as $column) {
                foreach (DB::table($table)->whereIn('id', $ids)->whereNotNull($column)->pluck($column) as $id) {
                    $byColumn[] = (int) $id;
                }
            }

            $owners[$table] = $ids;
        }

        $types = [];

        foreach (DB::table('media')->where('organization_id', $person->organizationId)->distinct()->pluck('model_type') as $type) {
            $table = self::tableOfMorph((string) $type);

            if ($table !== null && isset($owners[$table])) {
                $types[(string) $type] = $owners[$table];
            }
        }

        return $query->where(static function (Builder $where) use ($byColumn, $types): void {
            $where->whereIn('id', $byColumn);

            foreach ($types as $type => $ids) {
                $where->orWhere(static fn (Builder $owned): Builder => $owned->where('model_type', $type)->whereIn('model_id', $ids));
            }
        });
    }

    /** @return list<string> */
    public static function mediaColumns(string $table): array
    {
        static $columns = [];

        return $columns[$table] ??= array_values(array_filter(
            DB::getSchemaBuilder()->getColumnListing($table),
            static fn (string $column): bool => $column === 'media_id' || str_ends_with($column, '_media_id'),
        ));
    }

    /** The table of a media owner's model (its class or morph alias), if it is one. */
    public static function tableOfMorph(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return (new $class)->getTable();
    }
}
