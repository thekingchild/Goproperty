<?php

declare(strict_types=1);

namespace Modules\Properties\Import;

use App\Support\NigerianStates;
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
use Modules\Properties\Enums\OwnerIdType;
use Modules\Properties\Enums\OwnerType;
use Modules\Properties\Models\OwnerProfile;
use Modules\Properties\Models\Property;
use Modules\Properties\Owners\OwnerProfiles;
use WeakMap;

/**
 * The Owners sheet (DATA_IMPORT.md § 1 row 1): one property owner per row,
 * a person ("First Last") or a company. A row matches an owner by its
 * `external_ref` ("Your ID") first, then by phone, then by name
 * (`display_name`), archived owners included: an archived match is restored
 * (P02 decision 3, audited `owner.restored` in the owners log) and carries
 * the batch's id like a created owner, so a rollback archives it again. An
 * owner on file with another phone number is a different owner (namesakes
 * are common); the dry run warns about it.
 *
 * Like the tenants sheet (P02-06b), an empty cell keeps what is on file,
 * creates and updates are audited by the batch (`import.committed`), and
 * nobody is contacted. The ID number is never echoed in a message or the
 * preview (the report is plain JSON on the batch).
 *
 * The dry run remembers every valid row under the key `owners` (`ref:…`,
 * `phone:…`, `name:…`, and `name-twice:…` when two owners would share a
 * name), so the Buildings sheet can name an owner the file adds (resolve()).
 */
final class OwnersImportTarget implements ImportTarget
{
    public const string KEY = 'owners';

    private const string ROWS_BY_REF = 'owners:ref';

    private const string ROWS_BY_PHONE = 'owners:phone';

    private const string ROWS_BY_NAME = 'owners:name';

    private const string ROWS_WITHOUT_PHONE = 'owners:name-no-phone';

    /** @var WeakMap<ImportRun, array<string, string>> per dry run: the names of the owners the file adds, by remembered key */
    private WeakMap $names;

    public function __construct()
    {
        $this->names = new WeakMap;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Owners';
    }

    /** First: buildings name their owners (DATA_IMPORT.md § 2.4). */
    public function order(): int
    {
        return 10;
    }

    public function instructions(): string
    {
        return 'One row per landlord whose buildings you manage: a person or a company. GoProperty recognises an owner by Your ID, then the phone number, then the name, so importing the same owner again updates them instead of adding them twice. Owners are not contacted.';
    }

    public function columns(): array
    {
        return [
            ImportColumn::externalRef('O-001'),
            new ImportColumn('name', 'Name', required: true, aliases: ['owner', 'owner name', 'owners name', 'landlord', 'landlord name', 'full name', 'company', 'company name', 'name of owner'], example: 'Bola Adeyemi', help: 'A person\'s first and last name, or the company\'s name.'),
            new ImportColumn('type', 'Type', aliases: ['owner type', 'person or company', 'landlord type'], example: OwnerType::Person->label(), help: 'Person or Company. Empty: Person (an owner on file keeps theirs).', options: array_map(static fn (OwnerType $type): string => $type->label(), OwnerType::cases())),
            new ImportColumn('phone', 'Phone', aliases: ['phone number', 'phone no', 'mobile', 'mobile number', 'tel', 'telephone', 'gsm', 'whatsapp', 'owner phone', 'landlord phone'], example: '0802 555 0101', help: 'How GoProperty recognises the owner when there is no Your ID.'),
            new ImportColumn('email', 'Email', aliases: ['email address', 'e-mail', 'mail', 'owner email'], example: 'bola@example.com'),
            new ImportColumn('address', 'Address', aliases: ['owner address', 'home address', 'office address', 'contact address'], example: '3 Bourdillon Road, Ikoyi'),
            new ImportColumn('state', 'State', aliases: ['owner state'], example: 'Lagos', options: NigerianStates::ALL),
            new ImportColumn('lga', 'LGA', aliases: ['local government', 'local government area'], example: 'Eti-Osa'),
            new ImportColumn('id_type', 'ID type', aliases: ['means of id', 'identification', 'id document'], example: OwnerIdType::Nin->label(), help: 'Needed when there is an ID number.', options: array_map(static fn (OwnerIdType $type): string => $type->label(), OwnerIdType::cases())),
            new ImportColumn('id_number', 'ID number', aliases: ['id no', 'id value', 'nin', 'cac', 'rc number', 'identification number'], example: '12345678901', help: 'NIN: 11 digits. CAC number: as on the certificate, such as RC 1234567. Kept encrypted.'),
            new ImportColumn('notes', 'Notes', aliases: ['note', 'remarks', 'comment', 'comments'], example: 'Prefers calls after 4 pm'),
        ];
    }

    public function validate(ImportRow $row, ImportRun $run): array
    {
        $issues = ImportCells::refIssues($row, $run, self::ROWS_BY_REF, 'owner');
        $name = ImportCells::collapsed($row->text('name'));
        $phone = PhoneNumber::normalize($row->text('phone'));
        $typeText = $row->text('type');
        $type = $typeText !== null ? self::type($typeText) : null;

        if ($typeText !== null && $type === null) {
            $issues[] = RowIssue::error('type', __('Choose one of: :options.', ['options' => 'Person, Company']));
        }

        if ($row->text('phone') !== null && $phone === null) {
            $issues[] = RowIssue::error('phone', __('":phone" is not a phone number. Enter it like 0803 123 4567 or +234 803 123 4567.', ['phone' => mb_substr((string) $row->text('phone'), 0, 30)]));
        } elseif ($phone !== null && ($earlier = $run->rowOf(self::ROWS_BY_PHONE, $phone)) !== null) {
            $issues[] = RowIssue::error('phone', __('This phone number is already on row :row. Each owner goes on one row with their own number.', ['row' => $earlier]));
        } elseif ($phone !== null) {
            $run->remember(self::ROWS_BY_PHONE, $phone, $row->number);
        }

        if (($email = $row->text('email')) !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $issues[] = RowIssue::error('email', __('":email" is not an email address.', ['email' => mb_substr($email, 0, 60)]));
        }

        $issues = [
            ...$issues,
            ...ImportCells::plainText('address', $row->text('address'), 300),
            ...ImportCells::stateIssues($row),
            ...ImportCells::plainText('lga', ImportCells::collapsed($row->text('lga')), 64),
            ...ImportCells::plainText('notes', $row->text('notes'), 2000, ''),
            ...self::idIssues($row),
        ];

        if ($name === null) {
            $issues[] = RowIssue::error('name', __('Enter the owner\'s name: a person\'s first and last name, or the company\'s name.'));

            return $issues;
        }

        if (ImportCells::hasErrors($issues)) {
            // The name's own checks and the match need a readable row.
            return [...$issues, ...self::nameIssues($name, $type ?? OwnerType::Person)];
        }

        [$existing, $ambiguous] = $this->match($row);

        if ($ambiguous !== null) {
            return [...$issues, RowIssue::error(null, $ambiguous)];
        }

        $issues = [...$issues, ...self::nameIssues($name, $type ?? $existing->type ?? OwnerType::Person)];
        $issues = [...$issues, ...$this->inFileIssues($row, $run, $name, $phone)];

        if ($existing?->trashed() === true) {
            $issues[] = RowIssue::warning(null, __('This owner was archived. Importing brings them back.'));
        }

        if ($existing !== null && ($ref = $row->externalRef()) !== null && $existing->external_ref !== null && $existing->external_ref !== $ref) {
            $issues[] = RowIssue::warning(ImportColumn::EXTERNAL_REF, __('This owner was imported before with ID ":old". Their ID becomes ":new".', ['old' => $existing->external_ref, 'new' => $ref]));
        }

        $namesakes = $existing === null ? self::byName($name)->count() : 0;

        if ($namesakes > 0) {
            $issues[] = RowIssue::warning('name', __('An owner called :name is already on file with another phone number. This row adds a second owner; to update that one instead, use the phone number on file or Your ID.', ['name' => $name]));
        }

        if (! ImportCells::hasErrors($issues)) {
            $this->rememberOwner($row, $run, $name, $phone, twice: $namesakes > 0);
        }

        return $issues;
    }

    public function simulate(ImportRow $row, ImportRun $run): RowPlan
    {
        [$existing] = $this->match($row);
        $type = ($text = $row->text('type')) !== null ? self::type($text) : null;
        $type ??= $existing->type ?? OwnerType::Person;

        return new RowPlan(
            action: self::action($existing),
            // Never the ID number: the report is plain JSON on the batch.
            preview: [
                'name' => ImportCells::collapsed($row->text('name')),
                'type' => $type->label(),
                'phone' => PhoneNumber::normalize($row->text('phone')) ?? $existing?->phone,
                'email' => ($email = $row->text('email')) !== null ? mb_strtolower($email) : $existing?->email,
                'state' => ImportCells::state($row->text('state')) ?? $existing?->state,
            ],
        );
    }

    public function upsert(ImportRow $row, ImportRun $run): UpsertResult
    {
        [$existing] = $this->match($row);
        $action = self::action($existing);
        $owner = $existing ?? new OwnerProfile;

        $owner->fill(self::attributes($row, $owner));

        if (($ref = $row->externalRef()) !== null) {
            $owner->external_ref = $ref;
        }

        if ($action !== ImportAction::Update) {
            $owner->import_batch_id = $run->batchId();
            $owner->deleted_at = null;
        }

        $owner->save();

        if ($action === ImportAction::Restore) {
            activity(OwnerProfiles::LOG)
                ->event('owner.restored')
                ->performedOn($owner)
                ->withProperties(['owner' => $owner->display_name, 'import_batch_id' => $run->batchId(), 'row' => $row->number])
                ->log('Owner restored by an import');
        }

        return new UpsertResult($action, $owner->id);
    }

    public function rollback(int $batchId): int
    {
        return OwnerProfile::query()->where('import_batch_id', $batchId)->delete();
    }

    /**
     * An owner of the batch is used once a building that is not the
     * batch's own names them (added on the pages or by a later import):
     * archiving the owner would leave that building with an archived owner.
     */
    public function usedSince(int $batchId): bool
    {
        return Property::query()
            ->whereIn('owner_profile_id', OwnerProfile::query()->withTrashed()->where('import_batch_id', $batchId)->select('id'))
            ->where(static fn (Builder $query) => $query->whereNull('import_batch_id')->orWhere('import_batch_id', '!=', $batchId))
            ->exists();
    }

    /**
     * The owner a Buildings row names in its Owner cell: by Your ID, then
     * phone, then name; in a dry run also an owner the Owners sheet of the
     * same file adds or brings back. `owner` is null with `inFile` true for
     * an owner the file adds; `name` is the owner's name as it will be;
     * `error` explains a reference that cannot be used (unknown, ambiguous,
     * archived).
     *
     * @return array{owner: OwnerProfile|null, inFile: bool, name: string|null, error: string|null}
     */
    public function resolve(string $reference, ImportRun $run): array
    {
        $reference = (string) ImportCells::collapsed($reference);
        $named = $this->names[$run] ?? [];
        $found = static function (?OwnerProfile $owner, ?string $key) use ($named): array {
            $inFile = $key !== null && isset($named[$key]);

            return $owner?->trashed() === true && ! $inFile
                ? ['owner' => $owner, 'inFile' => false, 'name' => $owner->display_name, 'error' => ImportCells::message(':name is an archived owner. Add them to the Owners sheet to bring them back, or restore them on the Owners page.', ['name' => $owner->display_name])]
                : ['owner' => $owner, 'inFile' => $inFile, 'name' => $inFile ? $named[$key] : $owner?->display_name, 'error' => null];
        };

        $byRef = OwnerProfile::query()->withTrashed()->where('external_ref', $reference)->first();

        if ($byRef !== null || $run->knows(self::KEY, 'ref:'.$reference)) {
            return $found($byRef, 'ref:'.$reference);
        }

        $phone = preg_match('/^\+?[\d\s().\-]{7,}$/', $reference) === 1 ? PhoneNumber::normalize($reference) : null;

        if ($phone !== null) {
            $owners = OwnerProfile::query()->withTrashed()->where('phone', $phone)->limit(2)->get();

            if ($owners->count() > 1) {
                return ['owner' => null, 'inFile' => false, 'name' => null, 'error' => ImportCells::message('More than one owner has the phone number ":owner". Use Your ID from the Owners sheet.', ['owner' => $reference])];
            }

            if ($owners->count() === 1 || $run->knows(self::KEY, 'phone:'.$phone)) {
                return $found($owners->first(), 'phone:'.$phone);
            }
        }

        $key = ImportCells::nameKey($reference);
        $owners = self::byName($reference)->limit(2)->get();

        if ($owners->count() > 1 || $run->knows(self::KEY, 'name-twice:'.$key)) {
            return ['owner' => null, 'inFile' => false, 'name' => null, 'error' => ImportCells::message('More than one owner is called ":owner". Use their phone number or Your ID from the Owners sheet.', ['owner' => $reference])];
        }

        if ($owners->count() === 1 || $run->knows(self::KEY, 'name:'.$key)) {
            return $found($owners->first(), 'name:'.$key);
        }

        return ['owner' => null, 'inFile' => false, 'name' => null, 'error' => ImportCells::message('No owner is called or has the number ":owner", here or in the Owners sheet. Add them to the Owners sheet, or leave Owner empty when your organization owns the building.', ['owner' => mb_substr($reference, 0, 80)])];
    }

    /**
     * The owner the row is about, archived ones included, or an explanation
     * when the row could be more than one owner on file.
     *
     * @return array{0: OwnerProfile|null, 1: string|null}
     */
    private function match(ImportRow $row): array
    {
        $ref = $row->externalRef();

        if ($ref !== null && ($owner = OwnerProfile::query()->withTrashed()->where('external_ref', $ref)->first()) !== null) {
            return [$owner, null];
        }

        $name = (string) ImportCells::collapsed($row->text('name'));
        $phone = PhoneNumber::normalize($row->text('phone'));
        $ambiguous = ImportCells::message('More than one owner on file matches this row (:name). Add Your ID, or the phone number on file, so GoProperty knows which one.', ['name' => $name]);

        if ($phone !== null) {
            $owners = OwnerProfile::query()->withTrashed()->where('phone', $phone)->get();

            if ($owners->count() > 1) {
                $owners = $owners->filter(static fn (OwnerProfile $owner): bool => ImportCells::nameKey($owner->display_name) === ImportCells::nameKey($name))->values();
            }

            if ($owners->count() === 1) {
                return [$owners->first(), null];
            }

            if ($owners->count() > 1) {
                return [null, $ambiguous];
            }

            // A namesake with another phone number is someone else; one
            // without a phone on file is this owner, who gains the number.
            $owners = self::byName($name)->whereNull('phone')->limit(2)->get();
        } else {
            $owners = self::byName($name)->limit(2)->get();
        }

        return match ($owners->count()) {
            0 => [null, null],
            1 => [$owners->first(), null],
            default => [null, $ambiguous],
        };
    }

    /**
     * The same owner twice in the file: by phone, or by name when either
     * row has no phone (it would match the other one).
     *
     * @return list<RowIssue>
     */
    private function inFileIssues(ImportRow $row, ImportRun $run, string $name, ?string $phone): array
    {
        $earlier = $run->rowOf(self::ROWS_BY_NAME, $name);
        $earlierWithoutPhone = $run->rowOf(self::ROWS_WITHOUT_PHONE, $name);
        $run->remember(self::ROWS_BY_NAME, $name, $row->number);

        if ($phone === null) {
            $run->remember(self::ROWS_WITHOUT_PHONE, $name, $row->number);
        }

        if ($earlier !== null && ($phone === null || $earlierWithoutPhone !== null)) {
            return [RowIssue::error('name', __('This owner is already on row :row. Each owner goes on one row; give namesakes their own phone numbers.', ['row' => $earlierWithoutPhone ?? $earlier]))];
        }

        return [];
    }

    private function rememberOwner(ImportRow $row, ImportRun $run, string $name, ?string $phone, bool $twice): void
    {
        if (! $run->dryRun) {
            return;
        }

        $key = ImportCells::nameKey($name);
        $keys = ['name:'.$key];

        if (($ref = $row->externalRef()) !== null) {
            $keys[] = 'ref:'.$ref;
        }

        if ($phone !== null) {
            $keys[] = 'phone:'.$phone;
        }

        if ($twice || $run->knows(self::KEY, 'name:'.$key)) {
            $run->remember(self::KEY, 'name-twice:'.$key, $row->number);
        }

        $named = $this->names[$run] ?? [];

        foreach ($keys as $remembered) {
            $run->remember(self::KEY, $remembered, $row->number);
            $named[$remembered] ??= $name;
        }

        $this->names[$run] = $named;
    }

    /** @return Builder<OwnerProfile> owners with this display name (compared without case), archived ones included */
    private static function byName(string $name): Builder
    {
        return OwnerProfile::query()->withTrashed()->where('display_name', ImportCells::collapsed($name));
    }

    /** @return list<RowIssue> */
    private static function nameIssues(string $name, OwnerType $type): array
    {
        if ($type === OwnerType::Company) {
            return mb_strlen($name) < 2
                ? [RowIssue::error('name', __('Enter the company\'s name.'))]
                : ImportCells::plainText('name', $name, 160);
        }

        [$first, $last] = self::split($name);

        if ($last === null) {
            return [RowIssue::error('name', __('Enter the owner\'s first and last name, or choose Company as the type.'))];
        }

        return mb_strlen($first) > 80 || mb_strlen($last) > 80
            ? [RowIssue::error('name', __('Use at most 80 characters for the first name and for the last name.'))]
            : ImportCells::plainText('name', $name, 161);
    }

    /** @return list<RowIssue> */
    private static function idIssues(ImportRow $row): array
    {
        $typeText = $row->text('id_type');
        $number = $row->text('id_number');
        $type = $typeText !== null ? self::idType($typeText) : null;
        $options = implode(', ', array_map(static fn (OwnerIdType $t): string => $t->label(), OwnerIdType::cases()));

        if ($typeText !== null && $type === null) {
            return [RowIssue::error('id_type', __('Choose one of: :options.', ['options' => $options]))];
        }

        if ($number === null) {
            return $type !== null && $type !== OwnerIdType::None
                ? [RowIssue::error('id_number', __('Enter the ID number, or leave the ID type empty.'))]
                : [];
        }

        if ($type === null || $type === OwnerIdType::None) {
            return [RowIssue::error('id_type', __('Choose the type of ID for this number: NIN or CAC number.'))];
        }

        // Never the number itself in the message: the report is plain JSON.
        return preg_match((string) $type->pattern(), self::idValue($type, $number)) === 1 ? [] : [RowIssue::error('id_number', $type === OwnerIdType::Cac
            ? __('Enter the CAC number as on the certificate, such as RC 1234567.')
            : __('A NIN is 11 digits, such as 12345678901.'))];
    }

    private static function type(string $text): ?OwnerType
    {
        return match (ImportCells::nameKey($text)) {
            'person', 'individual', 'private', 'personal' => OwnerType::Person,
            'company', 'business', 'firm', 'organisation', 'organization', 'corporate' => OwnerType::Company,
            default => null,
        };
    }

    private static function idType(string $text): ?OwnerIdType
    {
        return match ((string) preg_replace('/[^a-z]/', '', mb_strtolower($text))) {
            'nin', 'nationalidentificationnumber', 'nationalid' => OwnerIdType::Nin,
            'cac', 'cacnumber', 'rc', 'rcnumber', 'cacrc', 'cacregistration' => OwnerIdType::Cac,
            'none', 'no', 'nil' => OwnerIdType::None,
            default => null,
        };
    }

    /** As OwnerProfiles keeps it: a NIN without spaces, a CAC number in capitals. */
    private static function idValue(OwnerIdType $type, string $number): string
    {
        return $type === OwnerIdType::Cac
            ? mb_strtoupper((string) ImportCells::collapsed($number))
            : (string) preg_replace('/[\s\-]+/', '', $number);
    }

    /**
     * A person's first and last name: the last word is the last name.
     *
     * @return array{0: string, 1: string|null}
     */
    private static function split(string $name): array
    {
        $parts = explode(' ', (string) ImportCells::collapsed($name));
        $last = count($parts) > 1 ? array_pop($parts) : null;

        return [implode(' ', $parts), $last];
    }

    private static function action(?OwnerProfile $existing): ImportAction
    {
        return match (true) {
            $existing === null => ImportAction::Create,
            $existing->trashed() => ImportAction::Restore,
            default => ImportAction::Update,
        };
    }

    /**
     * What the row sets: the name always, other cells only when the file
     * fills them in (an empty cell keeps what is on file).
     *
     * @return array<string, mixed>
     */
    private static function attributes(ImportRow $row, OwnerProfile $owner): array
    {
        $name = (string) ImportCells::collapsed($row->text('name'));
        $type = (($text = $row->text('type')) !== null ? self::type($text) : null) ?? ($owner->exists ? $owner->type : OwnerType::Person);
        [$first, $last] = self::split($name);

        $values = $type === OwnerType::Company
            ? ['type' => $type, 'company_name' => $name, 'first_name' => null, 'last_name' => null]
            : ['type' => $type, 'first_name' => $first, 'last_name' => $last, 'company_name' => null];

        if (($phone = PhoneNumber::normalize($row->text('phone'))) !== null) {
            $values['phone'] = $phone;
        }

        if (($email = $row->text('email')) !== null) {
            $values['email'] = mb_strtolower($email);
        }

        foreach (['address', 'lga'] as $column) {
            if (($text = ImportCells::collapsed($row->text($column))) !== null) {
                $values[$column] = $text;
            }
        }

        if (($state = ImportCells::state($row->text('state'))) !== null) {
            $values['state'] = $state;
        }

        if (($notes = $row->text('notes')) !== null) {
            $values['notes'] = $notes;
        }

        $idType = ($text = $row->text('id_type')) !== null ? self::idType($text) : null;
        $number = $row->text('id_number');

        if ($idType !== null && $idType !== OwnerIdType::None && $number !== null) {
            $values['id_type'] = $idType;
            $values['id_value'] = self::idValue($idType, $number);
        } elseif ($idType === OwnerIdType::None) {
            $values['id_type'] = OwnerIdType::None;
            $values['id_value'] = null;
        }

        return $values;
    }
}
