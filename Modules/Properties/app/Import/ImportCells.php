<?php

declare(strict_types=1);

namespace Modules\Properties\Import;

use App\Support\NigerianStates;
use Modules\Import\Contracts\ImportColumn;
use Modules\Import\Contracts\ImportRow;
use Modules\Import\Contracts\ImportRun;
use Modules\Import\Contracts\RowIssue;

/**
 * Cell checks shared by the Owners, Buildings and Units sheets
 * (DATA_IMPORT.md § 1 rows 1–3), worded like the pages' own checks.
 */
final class ImportCells
{
    /** `external_ref` is a 100-character column (DATABASE_SCHEMA.md § 8A). */
    public const int MAX_REF = 100;

    /**
     * A translated message, as a string (Laravel's __() may return an array
     * for a key naming a whole group; these keys are sentences).
     *
     * @param  array<string, string|int>  $replace
     */
    public static function message(string $text, array $replace = []): string
    {
        $message = __($text, $replace);

        return is_string($message) ? $message : $text;
    }

    /** "  Palm   Court " → "Palm Court"; empty → null. */
    public static function collapsed(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /** The key the dry run's memory and lookups compare names by: lower case, single spaces. */
    public static function nameKey(string $name): string
    {
        return mb_strtolower((string) self::collapsed($name));
    }

    /**
     * Your ID: at most 100 characters, once per sheet. `$memory` is the
     * dry-run memory key of the sheet's IDs.
     *
     * @return list<RowIssue>
     */
    public static function refIssues(ImportRow $row, ImportRun $run, string $memory, string $noun): array
    {
        $ref = $row->externalRef();

        if ($ref === null) {
            return [];
        }

        if (mb_strlen($ref) > self::MAX_REF) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('Use at most :max characters for your ID.', ['max' => self::MAX_REF]))];
        }

        if (($earlier = $run->rowOf($memory, $ref)) !== null) {
            return [RowIssue::error(ImportColumn::EXTERNAL_REF, __('This ID is already on row :row. Each :noun goes on one row.', ['row' => $earlier, 'noun' => $noun]))];
        }

        $run->remember($memory, $ref, $row->number);

        return [];
    }

    /**
     * Plain text (no markup, as the forms require) of at most `$max` characters.
     *
     * @param  string  $forbidden  the refused characters ('' for free text such as notes)
     * @return list<RowIssue>
     */
    public static function plainText(string $column, ?string $text, int $max, string $forbidden = '<>'): array
    {
        if ($text === null) {
            return [];
        }

        if ($forbidden !== '' && preg_match('/['.preg_quote($forbidden, '/').']/u', $text) === 1) {
            return [RowIssue::error($column, __('Use plain text only (no :characters).', ['characters' => implode(' or ', mb_str_split($forbidden))]))];
        }

        return mb_strlen($text) > $max ? [RowIssue::error($column, __('Use at most :max characters.', ['max' => $max]))] : [];
    }

    /** A Nigerian state as listed (App\Support\NigerianStates), in any letter case; Abuja means the FCT. */
    public static function state(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $key = self::nameKey($text);

        if (in_array($key, ['abuja', 'federal capital territory', 'federal capital territory (abuja)', 'fct abuja', 'fct, abuja'], true)) {
            return 'FCT';
        }

        foreach (NigerianStates::ALL as $state) {
            if (mb_strtolower($state) === $key || mb_strtolower($state).' state' === $key) {
                return $state;
            }
        }

        return null;
    }

    /** @return list<RowIssue> */
    public static function stateIssues(ImportRow $row, string $column = 'state'): array
    {
        $text = $row->text($column);

        return $text !== null && self::state($text) === null
            ? [RowIssue::error($column, __('":state" is not a Nigerian state. Choose one from the list, such as Lagos or FCT.', ['state' => mb_substr($text, 0, 40)]))]
            : [];
    }

    /** @param  list<RowIssue>  $issues */
    public static function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }
}
