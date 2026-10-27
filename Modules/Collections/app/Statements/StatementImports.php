<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use App\Banking\BankAccounts;
use App\Media\MediaUploads;
use App\Media\UploadRefused;
use App\Media\UploadValidator;
use App\Models\OrganizationBankAccount;
use App\Models\User;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Collections\Enums\DateOrder;
use Modules\Collections\Enums\StatementFormat;
use Modules\Collections\Enums\StatementStatus;
use Modules\Collections\Jobs\ProcessStatementImport;
use Modules\Collections\Models\BankStatementColumnMapping;
use Modules\Collections\Models\BankStatementImport;
use Modules\Import\Contracts\SpreadsheetReader;
use Modules\Import\Contracts\UnreadableSpreadsheet;

/**
 * The only writer of uploaded bank statements (ONBOARDING_COMPLIANCE.md
 * § 4.1): an org-owner or org-admin uploads one bank account's statement
 * (CSV or Excel), checks how its columns map, and the statement is kept
 * as evidence (media collection `statements`) while its lines are read on
 * the `imports` queue (Jobs\ProcessStatementImport, StatementReader).
 *
 * Checked before anything is stored: the policy (`statements.import`,
 * blocked while the organization is suspended and while staff
 * impersonate), the account (one of this organization's, not removed),
 * the file (UploadValidator: a real .xlsx workbook or plain-text CSV,
 * never a renamed script or another zip), its header (found in the first
 * rows) and, when the rows above the table name an account number, that
 * it is the chosen account's (compared by fingerprint, without decrypting
 * the stored number). The mapping used is saved for the account, so its
 * next statement maps itself. Audited under log `statements` (counts and
 * ids only, never narrations or account numbers).
 */
final readonly class StatementImports
{
    public const string LOG = 'statements';

    public const int SAMPLE_ROWS = 3;

    public function __construct(
        private SpreadsheetReader $reader,
        private UploadValidator $validator,
        private MediaUploads $uploads,
    ) {}

    /**
     * The accounts a statement can be uploaded for: every account of the
     * organization not removed (deactivated ones too: their old statements
     * still hold tenants' payments), active first.
     *
     * @return Collection<int, OrganizationBankAccount>
     */
    public function accounts(): Collection
    {
        return OrganizationBankAccount::query()->orderByDesc('active')->orderByDesc('is_default')->orderBy('id')->get();
    }

    /**
     * Reads the statement's layout without storing anything, for the upload
     * page: its header, the mapping offered and its first rows.
     *
     * @throws ValidationException
     */
    public function preview(User $by, mixed $accountId, UploadedFile $file): StatementPreview
    {
        Gate::forUser($by)->authorize('create', BankStatementImport::class);

        $account = $this->account($accountId);
        [, $layout, $sample] = $this->read($file);
        $this->assertAccount($layout, $account);
        $saved = $layout->fromSaved($this->saved($account));

        return new StatementPreview($account, $layout, $saved ?? $layout->guess(), $sample, $saved !== null);
    }

    /**
     * Stores the statement and queues its lines. `$choices` are the page's
     * column choices (`columns`: field => column position, `date_order`);
     * without them the offered mapping is used, which must then be complete.
     *
     * @param  array{columns?: array<string, mixed>, date_order?: string}|null  $choices
     *
     * @throws ValidationException
     */
    public function upload(User $by, mixed $accountId, UploadedFile $file, ?array $choices = null): BankStatementImport
    {
        Gate::forUser($by)->authorize('create', BankStatementImport::class);

        $account = $this->account($accountId);
        [$mime, $layout] = $this->read($file);
        $this->assertAccount($layout, $account);
        $mapping = $choices === null ? $layout->suggest($this->saved($account)) : $layout->mappingFrom(
            is_array($choices['columns'] ?? null) ? $choices['columns'] : [],
            DateOrder::tryFrom((string) ($choices['date_order'] ?? '')) ?? throw ValidationException::withMessages(['dateOrder' => __('Choose how the statement writes its dates.')]),
        );
        $problems = $mapping->problems();

        if ($problems !== []) {
            throw ValidationException::withMessages(array_combine(
                array_map(static fn (string $field): string => 'columns.'.$field, array_keys($problems)),
                array_values($problems),
            ));
        }

        $format = StatementFormat::fromMime($mime) ?? throw ValidationException::withMessages(['file' => __('Upload the statement as an Excel (.xlsx) or CSV file.')]);

        $import = DB::transaction(function () use ($by, $account, $file, $format, $mapping): BankStatementImport {
            // One upload of an account at a time, so its saved mapping is written once.
            OrganizationBankAccount::query()->withTrashed()->lockForUpdate()->find($account->id);

            $import = new BankStatementImport;
            $import->forceFill([
                'organization_bank_account_id' => $account->id,
                'file_name' => mb_substr(MediaUploads::label($file->getClientOriginalName()).'.'.$format->value, 0, 150),
                'format' => $format,
                'column_mapping' => $mapping->toArray(),
                'status' => StatementStatus::Processing,
                'uploaded_by_user_id' => $by->id,
            ])->save();

            $media = $this->uploads->add($import, BankStatementImport::COLLECTION, $file);
            $import->forceFill(['media_id' => $media->id])->save();

            $saved = BankStatementColumnMapping::query()->where('organization_bank_account_id', $account->id)->first() ?? new BankStatementColumnMapping;
            $saved->forceFill([
                'organization_bank_account_id' => $account->id,
                'columns' => $mapping->headers,
                'date_order' => $mapping->dateOrder,
                'saved_by_user_id' => $by->id,
            ])->save();

            activity(self::LOG)
                ->event('statement.uploaded')
                ->performedOn($import)
                ->causedBy($by)
                ->withProperties(['bank_account_id' => $account->id, 'format' => $format->value, 'media_id' => $media->id])
                ->log('Bank statement uploaded');

            return $import;
        }, 3);

        ProcessStatementImport::dispatch($import->organization_id, $import->id);

        return BankStatementImport::query()->findOrFail($import->id);
    }

    /** @throws ValidationException */
    private function account(mixed $accountId): OrganizationBankAccount
    {
        $id = is_int($accountId) ? $accountId : (is_string($accountId) && ctype_digit($accountId) ? (int) $accountId : null);
        $account = $id === null ? null : OrganizationBankAccount::query()->find($id);

        if (! $account instanceof OrganizationBankAccount) {
            throw ValidationException::withMessages(['accountId' => __('Choose one of the organization\'s bank accounts.')]);
        }

        return $account;
    }

    /**
     * The file's sniffed type, its layout and its first rows.
     *
     * @return array{string, StatementLayout, list<list<string>>}
     *
     * @throws ValidationException
     */
    private function read(UploadedFile $file): array
    {
        try {
            $checked = $this->validator->check(BankStatementImport::COLLECTION, $file);
        } catch (UploadRefused $refused) {
            throw ValidationException::withMessages(['file' => $refused->getMessage()]);
        }

        try {
            $rows = $this->reader->rows($checked->path, $checked->mime);
            $layout = StatementLayout::find(self::upTo($rows, StatementLayout::SEARCH_ROWS));
            $sample = $layout === null ? [] : self::sample($rows);
        } catch (UnreadableSpreadsheet) {
            throw ValidationException::withMessages(['file' => __('The file could not be read as a spreadsheet. Export the statement from your bank again.')]);
        }

        if ($layout === null) {
            throw ValidationException::withMessages(['file' => __('No column headings (such as Date, Narration, Debit, Credit, Balance) were found in the first :rows rows. Upload the statement as your bank exports it.', ['rows' => StatementLayout::SEARCH_ROWS])]);
        }

        return [$checked->mime, $layout, $sample];
    }

    /**
     * A statement whose heading names another account number is refused
     * (ONBOARDING_COMPLIANCE.md § 4.1: statements are per account).
     *
     * @throws ValidationException
     */
    private function assertAccount(StatementLayout $layout, OrganizationBankAccount $account): void
    {
        $numbers = $layout->accountNumbers();

        if ($numbers === []) {
            return;
        }

        foreach ($numbers as $number) {
            if (hash_equals($account->account_fingerprint, BankAccounts::fingerprint($account->bank_code, $number))) {
                return;
            }
        }

        throw ValidationException::withMessages(['accountId' => __('This statement is for account ···:last4, not :account. Choose that account, or upload this account\'s statement.', [
            'last4' => substr($numbers[0], -4),
            'account' => $account->bank_name.' '.$account->masked(),
        ])]);
    }

    private function saved(OrganizationBankAccount $account): ?BankStatementColumnMapping
    {
        return BankStatementColumnMapping::query()->where('organization_bank_account_id', $account->id)->first();
    }

    /**
     * The generator's rows up to the given row number, leaving it there.
     *
     * @param  Generator<int, list<mixed>>  $rows
     * @return Generator<int, list<mixed>>
     */
    private static function upTo(Generator $rows, int $last): Generator
    {
        while ($rows->valid()) {
            $number = (int) $rows->key();
            $cells = $rows->current();

            yield $number => $cells;

            if ($number >= $last) {
                return;
            }

            $rows->next();
        }
    }

    /**
     * The next non-empty rows after the header, as text.
     *
     * @param  Generator<int, list<mixed>>  $rows
     * @return list<list<string>>
     */
    private static function sample(Generator $rows): array
    {
        $sample = [];
        $rows->next();

        while ($rows->valid() && count($sample) < self::SAMPLE_ROWS) {
            $cells = array_map(static fn (mixed $cell): string => match (true) {
                $cell instanceof DateTimeInterface => $cell->format('d/m/Y'),
                is_scalar($cell) => mb_substr(trim((string) $cell), 0, 60),
                default => '',
            }, $rows->current());

            if (implode('', $cells) !== '') {
                $sample[] = $cells;
            }

            $rows->next();
        }

        return $sample;
    }
}
