<?php

declare(strict_types=1);

namespace Modules\Tenants\Import;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;
use Modules\Leasing\Contracts\TenantLeases;
use Modules\Tenants\Enums\KycStatus;
use Modules\Tenants\Enums\TenantIdType;
use Modules\Tenants\Models\TenantProfile;

/**
 * The Tenants sheet (DATA_IMPORT.md § 1 row 4): one tenant per row, the
 * phone number (E.164 through App\Support\PhoneNumber) is the natural key.
 * A row matches a tenant by its `external_ref` ("Your ID") first, then by
 * phone, archived tenants included: an archived match is restored (P02
 * decision 3, audited as `tenant.restored`) and carries the batch's id,
 * like a created tenant, so a rollback archives it again; an updated tenant
 * keeps its own provenance and its changes on rollback.
 *
 * Re-importing updates only the cells the file fills in: an empty cell
 * keeps what is on file. The import never sets the KYC status (new tenants
 * are unverified) and never contacts anyone (AGENT.md § 4); a different ID
 * number on a checked tenant makes them unverified again.
 *
 * For later sheets (leases, opening balances, P03-13a) the dry run
 * remembers every valid row under the key `tenants` and the tenant's E.164
 * phone (ImportRun::remember), so they can refer to a tenant the file
 * creates before it exists.
 */
final class TenantsImportTarget implements ImportTarget
{
    public const string KEY = 'tenants';

    /** Rows of this file by phone and by ID, for duplicates within the file (dry run). */
    private const string ROWS_BY_PHONE = 'tenants:phone';

    private const string ROWS_BY_REF = 'tenants:ref';

    /** Audit log of tenant records (P03-01a's writer uses it too). */
    public const string LOG = 'tenants';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Tenants';
    }

    /** After owners, properties and units (P02-06c), before leases (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 40;
    }

    public function instructions(): string
    {
        return 'One row per tenant. The phone number is how GoProperty recognises a tenant: importing the same number again updates that tenant instead of adding them twice. Imported tenants are not contacted; you invite them when you are ready.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('T-001'),
            new ImportColumn('first_name', 'First name', required: true, aliases: ['firstname', 'given name', 'forename', 'other names', 'name', 'full name', 'tenant name', 'tenant'], example: 'Adaeze', help: 'A full name ("Adaeze Okafor") is split into first and last name when Last name is empty.'),
            new ImportColumn('last_name', 'Last name', aliases: ['surname', 'lastname', 'family name'], example: 'Okafor', help: 'Required, unless First name holds the full name.'),
            new ImportColumn('phone', 'Phone', required: true, aliases: ['phone number', 'phone no', 'mobile', 'mobile number', 'tel', 'telephone', 'gsm', 'whatsapp', 'whatsapp number', 'tenant phone'], example: '0803 123 4567', help: 'Required. How GoProperty recognises the tenant; each tenant needs their own number.'),
            new ImportColumn('email', 'Email', aliases: ['email address', 'e-mail', 'mail'], example: 'adaeze@example.com'),
            new ImportColumn('address', 'Address', aliases: ['home address', 'current address', 'residential address'], example: '12 Allen Avenue, Ikeja'),
            new ImportColumn('occupation', 'Occupation', aliases: ['job', 'profession', 'work'], example: 'Accountant'),
            new ImportColumn('emergency_name', 'Next of kin', aliases: ['next of kin name', 'nok', 'emergency contact', 'emergency contact name', 'kin'], example: 'Chidi Okafor'),
            new ImportColumn('emergency_phone', 'Next of kin phone', aliases: ['nok phone', 'next of kin number', 'emergency contact phone', 'emergency phone'], example: '0805 987 6543'),
            new ImportColumn('emergency_relationship', 'Next of kin relationship', aliases: ['relationship', 'nok relationship'], example: 'Brother'),
            new ImportColumn('id_type', 'ID type', aliases: ['means of id', 'means of identification', 'identification', 'id document'], example: TenantIdType::Nin->label(), help: 'Needed when there is an ID number.', options: array_map(static fn (TenantIdType $type): string => $type->label(), TenantIdType::documents())),
            new ImportColumn('id_number', 'ID number', aliases: ['id no', 'id value', 'identification number', 'nin', 'nin number', 'passport number'], example: '12345678901', help: 'NIN: 11 digits. Passport: a letter and 8 digits. Kept encrypted.'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = [];
        [$first, $last] = self::names($row);
        $phone = PhoneNumber::normalize($row->text('phone'));
        $ref = $row->externalRef();

        if ($ref !== null && mb_strlen($ref) > 100) {
            $issues[] = RowIssue::error(ImportColumn::EXTERNAL_REF, __('Use at most 100 characters for your ID.'));
        } elseif ($ref !== null && ($earlier = $run->rowOf(self::ROWS_BY_REF, $ref)) !== null) {
            $issues[] = RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each tenant goes on one row.', ['row' => $earlier]));
        } elseif ($ref !== null) {
            $run->remember(self::ROWS_BY_REF, $ref, $row->number);
        }

        if ($first === null) {
            $issues[] = RowIssue::error('first_name', __('Enter the tenant\'s first name.'));
        } elseif ($last === null) {
            $issues[] = RowIssue::error('last_name', __('Enter the tenant\'s last name, or their full name under First name.'));
        }

        foreach (['first_name' => $first, 'last_name' => $last] as $column => $name) {
            if ($name !== null) {
                $issues = [...$issues, ...self::plainText($column, $name, 80)];
            }
        }

        if ($row->text('phone') === null) {
            $issues[] = RowIssue::error('phone', __('Enter the tenant\'s phone number.'));
        } elseif ($phone === null) {
            $issues[] = RowIssue::error('phone', __('":phone" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.', ['phone' => $row->text('phone')]));
        } elseif (($earlier = $run->rowOf(self::ROWS_BY_PHONE, $phone)) !== null) {
            $issues[] = RowIssue::error('phone', __('This phone number is already on row :row. Each tenant goes on one row with their own number.', ['row' => $earlier]));
        } else {
            $run->remember(self::ROWS_BY_PHONE, $phone, $row->number);
        }

        if (($email = $row->text('email')) !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $issues[] = RowIssue::error('email', __('":email" is not an email address.', ['email' => $email]));
        }

        foreach (['address' => 300, 'occupation' => 120, 'emergency_name' => 120, 'emergency_relationship' => 60] as $column => $max) {
            if (($text = $row->text($column)) !== null) {
                $issues = [...$issues, ...self::plainText($column, $text, $max)];
            }
        }

        if (($kin = $row->text('emergency_phone')) !== null && PhoneNumber::normalize($kin) === null) {
            $issues[] = RowIssue::error('emergency_phone', __('":phone" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.', ['phone' => $kin]));
        }

        $issues = [...$issues, ...self::idIssues($row)];

        if ($phone !== null) {
            $issues = [...$issues, ...$this->matchIssues($row, $phone)];
        }

        if ($phone !== null && array_filter($issues, static fn (RowIssue $issue): bool => $issue->isError()) === []) {
            $run->remember(self::KEY, $phone, $row->number);
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        [$first, $last] = self::names($row);
        $phone = PhoneNumber::normalize($row->text('phone'));
        $type = ($text = $row->text('id_type')) !== null ? TenantIdType::fromInput($text) : null;

        return new RowPlan(
            action: self::action($this->match($row, $phone)),
            // Never the ID number: the report is kept as plain JSON on the batch.
            preview: [
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'email' => $row->text('email') !== null ? mb_strtolower((string) $row->text('email')) : null,
                'occupation' => $row->text('occupation'),
                'id_type' => $type !== null && $row->text('id_number') !== null ? $type->label() : null,
            ],
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $phone = PhoneNumber::normalize($row->text('phone'));
        $existing = $this->match($row, $phone);
        $action = self::action($existing);
        $tenant = $existing ?? new TenantProfile;

        $tenant->fill(self::attributes($row, $tenant));

        if ($row->externalRef() !== null) {
            $tenant->external_ref = $row->externalRef();
        }

        if ($tenant->exists && $tenant->kyc_status !== KycStatus::Unverified && $tenant->isDirty(['id_type', 'id_value'])) {
            // The check was of another document.
            $tenant->kyc_status = KycStatus::Unverified;
        }

        if ($action !== ImportAction::Update) {
            $tenant->import_batch_id = $run->batchId();
            $tenant->deleted_at = null;
        }

        $tenant->save();

        if ($action === ImportAction::Restore) {
            activity(self::LOG)
                ->event('tenant.restored')
                ->performedOn($tenant)
                ->withProperties(['import_batch_id' => $run->batchId(), 'row' => $row->number])
                ->log('Tenant restored by an import');
        }

        return new UpsertResult($action, $tenant->id);
    }

    public function rollback(int $batchId): int
    {
        return TenantProfile::query()->where('import_batch_id', $batchId)->delete();
    }

    /**
     * A tenant who has been given portal access since (P03-01b) has been
     * used. P03-13a adds leases, bills and payments through their modules'
     * contracts.
     */
    public function usedSince(int $batchId): bool
    {
        // Used once a tenant of the batch has portal access or a lease
        // (P03-03a, through the Leasing module's contract).
        $leases = app()->bound(TenantLeases::class) ? app(TenantLeases::class) : null;

        return TenantProfile::query()->withTrashed()->where('import_batch_id', $batchId)
            ->where(fn (Builder $query) => $query->whereNotNull('user_id')
                ->when($leases instanceof TenantLeases, fn (Builder $query) => $query->orWhereIn('id', $leases->tenantIdsQuery())))
            ->exists();
    }

    /**
     * The tenant the row is about: by the agency's ID first, then by phone,
     * archived tenants included.
     */
    private function match(ImportRow $row, ?string $phone): ?TenantProfile
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($found = TenantProfile::query()->withTrashed()->where('external_ref', $ref)->first()) !== null) {
            return $found;
        }

        return $phone === null ? null : TenantProfile::query()->withTrashed()->where('phone', $phone)->first();
    }

    /** @return list<RowIssue> */
    private function matchIssues(ImportRow $row, string $phone): array
    {
        $issues = [];
        $existing = $this->match($row, $phone);

        if ($existing === null) {
            return [];
        }

        if ($existing->phone !== $phone) {
            // Matched by ID with a new number: the number must be free.
            $holder = TenantProfile::query()->withTrashed()->where('phone', $phone)->first();

            if ($holder !== null) {
                $issues[] = RowIssue::error('phone', __('This phone number belongs to another tenant, :name. Each tenant needs their own number.', ['name' => $holder->fullName()]));
            }
        } elseif (($ref = $row->externalRef()) !== null && $existing->external_ref !== null && $existing->external_ref !== $ref) {
            $issues[] = RowIssue::warning(ImportColumn::EXTERNAL_REF, __('This tenant was imported before with ID ":old". Their ID becomes ":new".', ['old' => $existing->external_ref, 'new' => $ref]));
        }

        if ($existing->trashed()) {
            $issues[] = RowIssue::warning(null, __('This tenant was archived. Importing brings them back.'));
        }

        if ($existing->kyc_status !== KycStatus::Unverified && ($number = $row->text('id_number')) !== null && $existing->id_value !== TenantIdType::normalise($number)) {
            $issues[] = RowIssue::warning('id_number', __('This tenant\'s ID was checked. A different ID number makes them unverified again.'));
        }

        return $issues;
    }

    /** @return list<RowIssue> */
    private static function idIssues(ImportRow $row): array
    {
        $typeText = $row->text('id_type');
        $number = $row->text('id_number');
        $type = $typeText !== null ? TenantIdType::fromInput($typeText) : null;
        $options = implode(', ', array_map(static fn (TenantIdType $t): string => $t->label(), TenantIdType::documents()));

        if ($typeText !== null && $type === null) {
            return [RowIssue::error('id_type', __('Choose one of: :options.', ['options' => $options]))];
        }

        if ($number === null) {
            return $type !== null && $type !== TenantIdType::None
                ? [RowIssue::error('id_number', __('Enter the ID number, or leave the ID type empty.'))]
                : [];
        }

        if ($type === null || $type === TenantIdType::None) {
            return [RowIssue::error('id_type', __('Choose the type of ID for this number: :options.', ['options' => $options]))];
        }

        // Never the number itself in the message: the report is plain JSON.
        return $type->accepts(TenantIdType::normalise($number)) ? [] : [RowIssue::error('id_number', __($type->formatHint()))];
    }

    /** @return list<RowIssue> */
    private static function plainText(string $column, string $text, int $max): array
    {
        if (preg_match('/[<>]/', $text) === 1) {
            return [RowIssue::error($column, __('Use plain text only (no < or >).'))];
        }

        return mb_strlen($text) > $max ? [RowIssue::error($column, __('Use at most :max characters.', ['max' => $max]))] : [];
    }

    /**
     * First and last name; a full name under First name is split when Last
     * name is empty (the last word is the last name).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function names(ImportRow $row): array
    {
        $first = self::collapsed($row->text('first_name'));
        $last = self::collapsed($row->text('last_name'));

        if ($first !== null && $last === null && str_contains($first, ' ')) {
            $parts = explode(' ', $first);
            $last = array_pop($parts);
            $first = implode(' ', $parts);
        }

        return [$first, $last];
    }

    private static function collapsed(?string $text): ?string
    {
        return $text === null ? null : (string) preg_replace('/\s+/u', ' ', $text);
    }

    private static function action(?TenantProfile $existing): ImportAction
    {
        return match (true) {
            $existing === null => ImportAction::Create,
            $existing->trashed() => ImportAction::Restore,
            default => ImportAction::Update,
        };
    }

    /**
     * What the row sets: names and phone always, other cells only when the
     * file fills them in (an empty cell keeps what is on file).
     *
     * @return array<string, mixed>
     */
    private static function attributes(ImportRow $row, TenantProfile $tenant): array
    {
        [$first, $last] = self::names($row);
        $values = ['first_name' => $first, 'last_name' => $last, 'phone' => PhoneNumber::normalize($row->text('phone'))];

        foreach (['email', 'address', 'occupation'] as $column) {
            if (($text = self::collapsed($row->text($column))) !== null) {
                $values[$column] = $text;
            }
        }

        $contact = $tenant->emergency_contact ?? [];
        $kin = [
            'name' => self::collapsed($row->text('emergency_name')),
            'phone' => PhoneNumber::normalize($row->text('emergency_phone')),
            'relationship' => self::collapsed($row->text('emergency_relationship')),
        ];

        if (array_filter($kin) !== []) {
            $values['emergency_contact'] = array_merge(['name' => null, 'phone' => null, 'relationship' => null], $contact, array_filter($kin));
        }

        $type = ($text = $row->text('id_type')) !== null ? TenantIdType::fromInput($text) : null;
        $number = $row->text('id_number');

        if ($number !== null && $type !== null) {
            $values['id_type'] = $type;
            $values['id_value'] = TenantIdType::normalise($number);
        } elseif ($type === TenantIdType::None) {
            $values['id_type'] = TenantIdType::None;
            $values['id_value'] = null;
        }

        return $values;
    }
}
