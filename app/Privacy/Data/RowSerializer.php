<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * One table row as an export writes it (P09-02): every column but the
 * secrets (TableRule::omits()), JSON columns as JSON, encrypted columns
 * (TableRule::$masked) as their last four characters only (ID numbers,
 * bank account numbers; default 12), the rest as stored. Times are as
 * stored (UTC), which the export's README says.
 */
final class RowSerializer
{
    public const string MASK = '••••';

    /** @return array<string, mixed> */
    public function row(object $row, TableRule $rule): array
    {
        $out = [];

        foreach (get_object_vars($row) as $column => $value) {
            if ($rule->omits($column)) {
                continue;
            }

            if (in_array($column, $rule->masked, true)) {
                $out[$column] = $this->masked($value);

                continue;
            }

            $out[$column] = $this->plain($value);
        }

        return $out;
    }

    private function masked(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($value);
        } catch (DecryptException) {
            return self::MASK;
        }

        // An encrypted array (a contact person's details) says only that it is set.
        if (str_starts_with($plain, '{') || str_starts_with($plain, '[')) {
            return self::MASK;
        }

        return mb_strlen($plain) <= 4 ? self::MASK : self::MASK.' '.mb_substr($plain, -4);
    }

    private function plain(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        return $value;
    }
}
