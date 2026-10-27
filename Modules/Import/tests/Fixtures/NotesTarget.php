<?php

declare(strict_types=1);

namespace Modules\Import\Tests\Fixtures;

use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Modules\Import\Contracts\ImportAction;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\ImportTarget;
use Modules\Import\Contracts\RowIssue;
use Modules\Import\Contracts\RowPlan;
use Modules\Import\Contracts\UpsertResult;

/**
 * A second test-only target that depends on the first: each note names a
 * contact by phone, which must exist already or be created by the Contacts
 * sheet of the same file (so it commits after it, whatever the sheet
 * order in the workbook).
 */
final class NotesTarget implements ImportTarget
{
    public function key(): string
    {
        return 'notes';
    }

    public function label(): string
    {
        return 'Notes';
    }

    public function order(): int
    {
        return 20;
    }

    public function instructions(): string
    {
        return 'Notes about contacts, by the contact\'s phone number.';
    }

    public function columns(): array
    {
        return [
            new ImportColumn('contact_phone', 'Contact phone', required: true, example: '08031234567'),
            new ImportColumn('note', 'Note', required: true, example: 'Prefers WhatsApp'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $phone = preg_replace('/\D/', '', (string) $row->text('contact_phone')) ?? '';
        $issues = [];

        if ($phone === '' || (! $run->knows('contacts', $phone) && ! ContactsTarget::table()->where('phone', $phone)->whereNull('deleted_at')->exists())) {
            $issues[] = RowIssue::error('contact_phone', 'No contact has this phone number, here or in the Contacts sheet.');
        }

        if ($row->text('note') === null) {
            $issues[] = RowIssue::error('note', 'Enter the note.');
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        return new RowPlan(ImportAction::Create, ['contact_phone' => $row->text('contact_phone'), 'note' => $row->text('note')]);
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        $phone = preg_replace('/\D/', '', (string) $row->text('contact_phone')) ?? '';
        $contact = ContactsTarget::table()->where('phone', $phone)->whereNull('deleted_at')->value('id');

        if ($contact === null) {
            return new UpsertResult(ImportAction::Skip, null, 'The contact is gone.');
        }

        $id = DB::table(ImportFixtures::NOTES)->insertGetId([
            'organization_id' => app(OrganizationContext::class)->id(),
            'contact_id' => $contact,
            'note' => $row->text('note'),
            'import_batch_id' => $run->batchId(),
            'created_at' => now(),
        ]);

        return new UpsertResult(ImportAction::Create, $id);
    }

    public function rollback(int $batchId): int
    {
        return DB::table(ImportFixtures::NOTES)->where('organization_id', app(OrganizationContext::class)->id())
            ->where('import_batch_id', $batchId)->whereNull('deleted_at')->update(['deleted_at' => now()]);
    }

    public function usedSince(int $batchId): bool
    {
        return false;
    }
}
