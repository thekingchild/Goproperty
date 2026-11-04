<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Privacy\Data\PersonalDataMap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Modules\Payments\Models\PaymentProof;
use Modules\Payments\Models\Receipt;
use Modules\Tickets\Models\TicketMessage;

/**
 * A world for the NDPA tooling's tests (P09-02): rows written straight into
 * the tables, so a tenant's data reaches every table PersonalDataMap says
 * holds tenants' rows. tenant() returns, per table, exactly the rows that
 * are that tenant's; anything else it writes (an internal note on their
 * ticket, the organization's own records) is someone else's.
 *
 * insert() fills the columns a test does not care about with harmless
 * values (from the schema), so a row needs only the columns that matter.
 */
final class PersonalDataWorld
{
    /** @var array<string, array<string, array{nullable: bool, default: mixed, type: string, auto: bool}>> */
    private static array $columns = [];

    /** @param  array<string, mixed>  $values */
    public static function insert(string $table, array $values): int
    {
        $row = $values;

        foreach (self::columns($table) as $name => $column) {
            if (array_key_exists($name, $row) || $column['nullable'] || $column['default'] !== null || $column['auto']) {
                continue;
            }

            $row[$name] = match (true) {
                in_array($name, ['created_at', 'updated_at'], true) => now(),
                str_contains($column['type'], 'char(') => Str::lower(Str::random(min(20, (int) (preg_replace('/\D/', '', $column['type']) ?: 10)))),
                str_starts_with($column['type'], 'uuid') => (string) Str::uuid(),
                str_contains($column['type'], 'int') || str_contains($column['type'], 'decimal') => 1,
                str_starts_with($column['type'], 'date') => '2026-01-01',
                str_starts_with($column['type'], 'timestamp') => now(),
                str_contains($column['type'], 'json') || str_contains($column['type'], 'longtext') => '{}',
                default => Str::lower(Str::random(12)),
            };
        }

        $row += ['created_at' => now(), 'updated_at' => now()];
        $row = array_intersect_key($row, self::columns($table));

        return (int) DB::table($table)->insertGetId($row);
    }

    /**
     * The organization's own records a tenant's rows hang on.
     *
     * @return array<string, int>
     */
    public static function building(int $organizationId): array
    {
        $owner = self::insert('owner_profiles', ['organization_id' => $organizationId, 'type' => 'individual', 'first_name' => 'Kunle', 'last_name' => 'Landlord', 'display_name' => 'Kunle Landlord', 'phone' => '+2348020000'.random_int(100, 999)]);
        $property = self::insert('properties', ['organization_id' => $organizationId, 'owner_profile_id' => $owner, 'name' => 'Palm Court '.$organizationId, 'slug' => 'palm-court-'.Str::random(6), 'status' => 'active']);
        $unit = self::insert('units', ['organization_id' => $organizationId, 'property_id' => $property, 'unit_label' => 'Flat '.random_int(1, 999), 'status' => 'vacant']);
        $group = self::insert('building_groups', ['organization_id' => $organizationId, 'property_id' => $property, 'name' => 'Palm Court residents', 'is_active' => true]);
        $account = self::insert('organization_bank_accounts', ['organization_id' => $organizationId, 'bank_code' => '058', 'bank_name' => 'GTBank', 'account_number' => Crypt::encryptString('0123456789'), 'account_number_last4' => '6789', 'account_name' => 'Agency Ltd', 'account_fingerprint' => Str::random(64), 'active' => true]);
        $statement = self::insert('bank_statement_imports', ['organization_id' => $organizationId, 'organization_bank_account_id' => $account, 'file_name' => 'statement.csv', 'status' => 'processed']);
        $handover = self::insert('management_handovers', ['organization_id' => $organizationId, 'to_organization_id' => $organizationId, 'landlord_organization_id' => $organizationId, 'kind' => 'transfer', 'status' => 'accepted']);

        return compact('owner', 'property', 'unit', 'group', 'account', 'statement', 'handover');
    }

    /**
     * A tenant and their rows in every table the map says holds tenants' rows.
     *
     * @param  array<string, int>  $building
     * @return array<string, list<int>>
     */
    public static function tenant(int $organizationId, array $building, string $first, string $last, string $phone, ?string $email, ?int $userId, string $idNumber = '12345678901'): array
    {
        $o = ['organization_id' => $organizationId];
        $name = "{$first} {$last}";
        $mine = [];
        $add = static function (string $table, array $values) use (&$mine, $o): int {
            $id = self::insert($table, $o + $values);
            $mine[$table][] = $id;

            return $id;
        };

        $tenant = $add('tenant_profiles', ['user_id' => $userId, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone, 'address' => '12 Allen Avenue, Ikeja', 'occupation' => 'Engineer', 'emergency_contact' => json_encode(['name' => 'Bisi Relative', 'phone' => '+2348039990000']), 'kyc_status' => 'verified', 'id_type' => 'nin', 'id_value' => Crypt::encryptString($idNumber)]);
        $lease = $add('leases', ['property_id' => $building['property'], 'unit_id' => $building['unit'], 'tenant_profile_id' => $tenant, 'contract_number' => 'LC-'.Str::random(8), 'billing_cycle' => 'annual', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'rent_amount_kobo' => 150_000_000, 'deposit_kobo' => 10_000_000, 'status' => 'expired', 'expired_at' => '2026-01-01 00:00:00', 'notes' => "Quiet tenant, {$name}"]);
        $add('lease_deposit_entries', ['lease_id' => $lease, 'type' => 'received', 'amount_kobo' => 10_000_000, 'note' => "Deposit from {$name}"]);
        $add('lease_contracts', ['lease_id' => $lease, 'contract_number' => 'LC-'.Str::random(8), 'verification_code' => strtoupper(Str::random(10)), 'details' => json_encode(['tenant' => ['name' => $name, 'phone' => $phone, 'email' => $email]]), 'clauses' => "The tenant {$name} agrees to keep the flat clean.", 'issued_at' => now()]);
        $bill = $add('bills', ['type' => 'rent', 'billable_type' => 'lease', 'billable_id' => $lease, 'lease_id' => $lease, 'tenant_profile_id' => $tenant, 'property_id' => $building['property'], 'unit_id' => $building['unit'], 'number' => 'INV-'.Str::random(8), 'payment_reference' => 'PAY'.Str::random(8), 'description' => 'Rent 2025', 'amount_kobo' => 150_000_000, 'paid_kobo' => 150_000_000, 'due_date' => '2025-01-01', 'status' => 'paid', 'verification_code' => strtoupper(Str::random(10)), 'details' => json_encode(['tenant' => ['name' => $name, 'phone' => $phone, 'email' => $email]])]);
        $add('bill_items', ['bill_id' => $bill, 'label' => 'Rent', 'amount_kobo' => 150_000_000, 'quantity' => 1]);
        $payment = $add('payments', ['tenant_profile_id' => $tenant, 'bill_id' => $bill, 'amount_kobo' => 150_000_000, 'method' => 'transfer', 'source' => 'proof', 'status' => 'confirmed', 'paid_at' => '2025-01-02', 'payer_name' => $name, 'payer_type' => 'tenant', 'reference' => 'TRF'.Str::random(8), 'idempotency_key' => Str::random(20), 'note' => "Paid by {$name}"]);
        $add('payment_allocations', ['payment_id' => $payment, 'bill_id' => $bill, 'amount_kobo' => 150_000_000]);
        $receipt = $add('receipts', ['payment_id' => $payment, 'tenant_profile_id' => $tenant, 'number' => 'RCT-'.Str::random(8), 'verification_code' => strtoupper(Str::random(10)), 'amount_kobo' => 150_000_000, 'issued_at' => now(), 'details' => json_encode(['tenant' => ['name' => $name, 'phone' => $phone]])]);
        $proof = $add('payment_proofs', ['payment_id' => $payment, 'tenant_profile_id' => $tenant, 'bill_id' => $bill, 'amount_kobo' => 150_000_000, 'paid_on' => '2025-01-02', 'sender_name' => $name, 'bank_name' => 'GTBank', 'file_sha256' => hash('sha256', $name), 'submitted_by' => 'tenant', 'submitted_at' => now()]);
        $add('payment_notices', ['payment_id' => $payment, 'event' => 'confirmed', 'channels' => '[]']);
        $reference = 'PSK'.Str::random(10);
        $add('paystack_transactions', ['bill_id' => $bill, 'tenant_profile_id' => $tenant, 'reference' => $reference, 'amount_kobo' => 150_000_000, 'currency' => 'NGN', 'mode' => 'test', 'status' => 'success']);
        $add('webhook_log', ['provider' => 'paystack', 'event' => 'charge.success', 'reference' => $reference, 'payload' => json_encode(['data' => ['customer' => ['email' => $email, 'name' => $name]]]), 'signature_valid' => true, 'received_at' => now()]);
        $add('bank_statement_lines', ['bank_statement_import_id' => $building['statement'], 'organization_bank_account_id' => $building['account'], 'row_number' => random_int(1, 99999), 'transaction_date' => '2025-01-02', 'direction' => 'credit', 'amount_kobo' => 150_000_000, 'narration' => "TRF FROM {$name} RENT", 'counterparty_name' => $name, 'line_hash' => Str::random(64), 'match_status' => 'matched', 'tenant_profile_id' => $tenant, 'payment_id' => $payment]);
        $add('tenant_credit_entries', ['tenant_profile_id' => $tenant, 'amount_kobo' => 5_000, 'reason' => 'overpayment', 'payment_id' => $payment, 'note' => "Overpaid by {$name}"]);
        $add('tenant_credit_balances', ['tenant_profile_id' => $tenant, 'balance_kobo' => 5_000]);
        $add('opening_balances', ['tenant_profile_id' => $tenant, 'property_id' => $building['property'], 'unit_id' => $building['unit'], 'as_of_date' => '2024-12-31', 'amount_kobo' => 0, 'description' => "Opening balance for {$name}"]);
        $ticket = $add('tickets', ['number' => 'TK-'.Str::random(6), 'property_id' => $building['property'], 'unit_id' => $building['unit'], 'lease_id' => $lease, 'tenant_profile_id' => $tenant, 'source' => 'tenant', 'raised_by_user_id' => $userId, 'category' => 'plumbing', 'priority' => 'normal', 'status' => 'closed', 'title' => 'Leaking tap', 'description' => "The kitchen tap leaks. Call me on {$phone}."]);
        $message = $add('ticket_messages', ['ticket_id' => $ticket, 'author_kind' => 'tenant', 'author_user_id' => $userId, 'body' => "Please come after 5pm. {$name}", 'is_internal' => false, 'attachment_count' => 1]);
        // The agency's internal note on their ticket is not theirs (default 9).
        self::insert('ticket_messages', $o + ['ticket_id' => $ticket, 'author_kind' => 'staff', 'author_user_id' => null, 'body' => "Internal: {$name} is often late with rent.", 'is_internal' => true]);
        $add('ticket_status_history', ['ticket_id' => $ticket, 'from_status' => 'open', 'to_status' => 'closed', 'note' => 'Fixed', 'changed_at' => now()]);
        $add('ticket_notices', ['ticket_id' => $ticket, 'key' => 'ticket.replied', 'recipient' => 'tenant', 'user_id' => $userId, 'side' => 'tenant', 'event' => 'replied', 'channels' => '[]']);
        $add('group_members', ['building_group_id' => $building['group'], 'member_type' => 'tenant', 'tenant_profile_id' => $tenant, 'user_id' => $userId, 'role' => 'member', 'joined_at' => now()]);
        $post = $add('group_posts', ['building_group_id' => $building['group'], 'author_kind' => 'tenant', 'author_user_id' => $userId, 'author_tenant_profile_id' => $tenant, 'body' => "Hello neighbours, I am {$name}.", 'status' => 'visible', 'last_activity_at' => now()]);
        $add('group_comments', ['group_post_id' => $post, 'author_kind' => 'tenant', 'author_user_id' => $userId, 'author_tenant_profile_id' => $tenant, 'body' => 'Thanks all!', 'status' => 'visible']);
        $add('group_reactions', ['user_id' => $userId, 'reactable_type' => 'group_post', 'reactable_id' => $post, 'emoji' => 'like']);
        $add('group_reports', ['building_group_id' => $building['group'], 'reportable_type' => 'group_post', 'reportable_id' => $post, 'reporter_user_id' => $userId, 'reporter_kind' => 'tenant', 'reporter_tenant_profile_id' => $tenant, 'reason' => 'spam', 'note' => 'Spam', 'status' => 'open']);
        $add('notification_deliveries', ['notification_type' => 'reminder', 'channel' => 'sms', 'recipient_user_id' => $userId, 'recipient_contact' => $phone, 'subject' => null, 'body' => "Dear {$first}, rent is due.", 'status' => 'delivered', 'dedupe_key' => Str::random(40), 'queued_at' => now()]);
        $add('reminders', ['type' => 'rent_due', 'remindable_type' => 'lease', 'remindable_id' => $lease, 'lease_id' => $lease, 'due_date' => '2025-12-01', 'offset_days' => 7, 'send_on' => '2025-11-24', 'channel' => 'sms', 'recipient' => 'tenant', 'recipient_user_id' => $userId, 'recipient_tenant_profile_id' => $tenant, 'recipient_email' => $email, 'recipient_phone' => $phone, 'status' => 'sent', 'dedupe_key' => Str::random(40)]);
        $add('whatsapp_opt_ins', ['tenant_profile_id' => $tenant, 'phone' => $phone, 'opted_in_at' => now(), 'source' => 'agency']);
        $add('invitations', ['kind' => 'tenant', 'email' => $email, 'phone' => $phone, 'user_id' => $userId, 'tenant_profile_id' => $tenant, 'roles' => '[]', 'token_hash' => hash('sha256', Str::random(40)), 'channel' => 'email', 'status' => 'accepted', 'expires_at' => now()->addDays(14)]);
        $add('management_handover_tenants', ['management_handover_id' => $building['handover'], 'tenant_profile_id' => $tenant, 'tenant_name' => $name, 'credit_kobo' => 0, 'unpaid_bills' => 0, 'unpaid_kobo' => 0]);

        if ($userId !== null) {
            $add('consent_records', ['user_id' => $userId, 'document' => 'terms', 'granted' => true, 'version' => 'v-test', 'source' => 'tenant_invitation', 'ip_address' => '102.89.1.1', 'user_agent' => 'Phone browser', 'recorded_at' => now()]);
        }

        // Files: the proof's (owned by it, and pointed at), a ticket attachment (owned) and the issued receipt (pointed at).
        $mine['media'][] = $proofFile = self::file($organizationId, (new PaymentProof)->getMorphClass(), $proof, 'payment_proofs', "proof-{$first}.pdf");
        DB::table('payment_proofs')->where('id', $proof)->update(['media_id' => $proofFile]);
        $mine['media'][] = self::file($organizationId, (new TicketMessage)->getMorphClass(), $message, 'ticket_attachments', "tap-{$first}.jpg");
        $mine['media'][] = $receiptFile = self::file($organizationId, (new Receipt)->getMorphClass(), $receipt, 'issued_documents', 'receipt.pdf');
        DB::table('receipts')->where('id', $receipt)->update(['document_media_id' => $receiptFile]);

        foreach (app(PersonalDataMap::class)->rules() as $table => $rule) {
            if ($rule->person !== null && ! isset($mine[$table]) && ! ($table === 'consent_records' && $userId === null)) {
                throw new LogicException("The world gives the tenant no row in {$table}: add one here.");
            }
        }

        return array_map(static fn (array $ids): array => array_values($ids), $mine);
    }

    /** A stored file (the bytes on the faked media disk) and its media row. */
    public static function file(int $organizationId, string $modelType, int $modelId, string $collection, string $fileName): int
    {
        $uuid = (string) Str::uuid();
        $bytes = "%PDF-1.4 {$fileName} ".Str::random(16);
        Storage::disk('media')->put("{$organizationId}/{$uuid}/{$fileName}", $bytes);

        return self::insert('media', [
            'organization_id' => $organizationId,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'uuid' => $uuid,
            'collection_name' => $collection,
            'name' => $fileName,
            'file_name' => $fileName,
            'mime_type' => str_ends_with($fileName, '.jpg') ? 'image/jpeg' : 'application/pdf',
            'disk' => 'media',
            'conversions_disk' => 'media',
            'size' => strlen($bytes),
            'manipulations' => '[]',
            'custom_properties' => json_encode(['sha256' => hash('sha256', $bytes)]),
            'generated_conversions' => '[]',
            'responsive_images' => '[]',
        ]);
    }

    /** Every row id of the organization, per table with `organization_id`. @return array<string, list<int>> */
    public static function organizationRows(int $organizationId): array
    {
        $rows = [];

        foreach (app(PersonalDataMap::class)->tables() as $table) {
            $rows[$table] = array_values(array_map(intval(...), DB::table($table)->where('organization_id', $organizationId)->orderBy('id')->pluck('id')->all()));
        }

        return $rows;
    }

    public static function lagos(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Africa/Lagos');
    }

    /** @return array<string, array{nullable: bool, default: mixed, type: string, auto: bool}> */
    private static function columns(string $table): array
    {
        if (! isset(self::$columns[$table])) {
            foreach (Schema::getColumns($table) as $column) {
                self::$columns[$table][$column['name']] = [
                    'nullable' => (bool) $column['nullable'],
                    'default' => $column['default'],
                    'type' => strtolower((string) $column['type']),
                    'auto' => (bool) $column['auto_increment'],
                ];
            }
        }

        return self::$columns[$table];
    }
}
