<?php

declare(strict_types=1);

namespace App\Banking;

/**
 * The banks an organization can add an account at (config/banks.php):
 * Paystack bank code => name.
 */
final class BankCatalogue
{
    /** @return array<string, string> code => name, by name */
    public function all(): array
    {
        $banks = config('banks.banks', []);
        $banks = is_array($banks) ? $banks : [];
        $clean = [];

        foreach ($banks as $code => $name) {
            if (is_string($name)) {
                $clean[(string) $code] = $name;
            }
        }

        asort($clean);

        return $clean;
    }

    public function name(string $code): ?string
    {
        return $this->all()[$code] ?? null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(strval(...), array_keys($this->all()));
    }
}
