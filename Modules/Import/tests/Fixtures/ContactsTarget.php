<?php

declare(strict_types=1);

namespace Modules\Import\Tests\Fixtures;

use App\Support\Money;
use App\Tenancy\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\InvalidCellValue;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;

/**
 * A test-only import target (P02-06a builds the engine before any real
 * target exists): contacts with a phone as the natural key, a date, an
 * amount and a fixed-value column, kept in a temporary table
 * (ImportFixtures). Written the way a real target would be: matching by
 * external_ref then phone, archived rows included (restored), provenance
 * on created and restored rows, rollback and used-since by batch.
 */
final class ContactsTarget implements ImportTarget
{
    public const array CATEGORIES = ['Gold', 'Silver', 'Bronze'];

    /** A row number upsert() throws on, to test a failing commit job. */
    public static ?string $failOnName = null;

    public function key(): string
    {
        return 'contacts';
    }

    public function label(): string
    {
        return 'Contacts';
    }

    public function order(): int
    {
        return 10;
    }

    public function instructions(): string
    {
        return 'One row per contact. The phone number is how a contact is recognised again.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('C-001'),
            new ImportColumn('name', 'Name', required: true, aliases: ['full name', 'contact name'], example: 'Ada Obi'),
            new ImportColumn('phone', 'Phone', required: true, aliases: ['phone number', 'mobile', 'tel'], example: '08031234567'),
            new ImportColumn('joined_on', 'Joined on', aliases: ['date joined'], example: '31/12/2025', help: 'DD/MM/YYYY'),
            new ImportColumn('balance', 'Balance', aliases: ['amount owed'], example: '₦150,000'),
            new ImportColumn('category', 'Category', example: 'Gold', options: self::CATEGORIES),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = [];
        $phone = self::phone($row);

        if ($row->text('name') === null) {
            $issues[] = RowIssue::error('name', 'Enter the contact\'s name.');
        }

        if ($row->text('phone') === null) {
            $issues[] = RowIssue::error('phone', 'Enter a phone number.');
        } elseif ($phone === null) {
            $issues[] = RowIssue::error('phone', 'Enter a phone number such as 0803 123 4567.');
        } elseif (($first = $run->rowOf('contacts', $phone)) !== null) {
            $issues[] = RowIssue::error('phone', "This phone number is already on row {$first}.");
        }

        foreach (['joined_on' => fn () => $row->date('joined_on'), 'balance' => fn () => $row->amountKobo('balance'), 'category' => fn () => $row->choice('category', self::CATEGORIES)] as $column => $read) {
            try {
                $value = $read();

                if ($column === 'balance' && is_int($value) && $value < 0) {
                    $issues[] = RowIssue::error('balance', 'The balance cannot be negative.');
                }

                if ($column === 'joined_on' && $value instanceof \DateTimeInterface && $value > now()) {
                    $issues[] = RowIssue::warning('joined_on', 'This date is in the future.');
                }
            } catch (InvalidCellValue $invalid) {
                $issues[] = RowIssue::error($column, $invalid->getMessage());
            }
        }

        if ($phone !== null && array_filter($issues, fn (RowIssue $issue): bool => $issue->isError()) === []) {
            $run->remember('contacts', $phone, $row->number);
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        $existing = $this->match($row);

        return new RowPlan(
            action: $existing === null ? ImportAction::Create : ($existing->deleted_at !== null ? ImportAction::Restore : ImportAction::Update),
            preview: [
                'name' => $row->text('name'),
                'phone' => self::phone($row),
                'balance' => Money::kobo($row->amountKobo('balance') ?? 0)->format(),
            ],
            units: 1,
            yearlyCollectionsKobo: $row->amountKobo('balance') ?? 0,
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        if (self::$failOnName !== null && $row->text('name') === self::$failOnName) {
            throw new \RuntimeException('Fixture failure.');
        }

        $values = [
            'name' => $row->text('name'),
            'phone' => self::phone($row),
            'joined_on' => $row->date('joined_on')?->toDateString(),
            'balance_kobo' => $row->amountKobo('balance'),
            'category' => $row->choice('category', self::CATEGORIES),
            'updated_at' => now(),
        ];

        if ($row->externalRef() !== null) {
            $values['external_ref'] = $row->externalRef();
        }

        $existing = $this->match($row);

        if ($existing === null) {
            $id = self::table()->insertGetId($values + ['organization_id' => self::organizationId(), 'import_batch_id' => $run->batchId(), 'created_at' => now()]);

            return new UpsertResult(ImportAction::Create, $id);
        }

        if ($existing->deleted_at !== null) {
            self::table()->where('id', $existing->id)->update($values + ['deleted_at' => null, 'import_batch_id' => $run->batchId()]);

            return new UpsertResult(ImportAction::Restore, (int) $existing->id);
        }

        self::table()->where('id', $existing->id)->update($values);

        return new UpsertResult(ImportAction::Update, (int) $existing->id);
    }

    public function rollback(int $batchId): int
    {
        return self::table()->where('import_batch_id', $batchId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }

    public function usedSince(int $batchId): bool
    {
        return self::table()->where('import_batch_id', $batchId)->whereNotNull('used_at')->exists();
    }

    /** The contacts table, limited to the current organization (the fixture has no model). */
    public static function table(): Builder
    {
        return DB::table(ImportFixtures::CONTACTS)->where('organization_id', self::organizationId());
    }

    private function match(ImportRow $row): ?object
    {
        if ($row->externalRef() !== null && ($found = self::table()->where('external_ref', $row->externalRef())->first()) !== null) {
            return $found;
        }

        $phone = self::phone($row);

        return $phone === null ? null : self::table()->where('phone', $phone)->first();
    }

    private static function phone(ImportRow $row): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $row->text('phone')) ?? '';

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    private static function organizationId(): int
    {
        return app(OrganizationContext::class)->id();
    }
}
