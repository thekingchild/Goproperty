<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One tenant of one organization, as the NDPA tooling sees them (P09-02):
 * the tenant's record in that organization, the portal account linked to
 * it (if any), and the values that identify them in free text (names,
 * email, phone in the forms it is written, address, ID number, emergency
 * contact), used only to find and remove those values and never stored.
 * The same person renting from another organization is a different
 * Person: nothing here reaches across organizations.
 */
final readonly class Person
{
    /**
     * @param  list<string>  $emails
     * @param  list<string>  $phones
     * @param  list<string>  $knownValues  longest first
     */
    public function __construct(
        public int $organizationId,
        public int $tenantProfileId,
        public ?int $userId,
        public array $emails,
        public array $phones,
        public array $knownValues,
    ) {}

    public static function load(int $organizationId, int $tenantProfileId): self
    {
        $row = DB::table('tenant_profiles')->where('organization_id', $organizationId)->where('id', $tenantProfileId)->first();

        if ($row === null) {
            throw new RuntimeException("Organization #{$organizationId} has no tenant #{$tenantProfileId}.");
        }

        $first = trim((string) ($row->first_name ?? ''));
        $last = trim((string) ($row->last_name ?? ''));
        $email = is_string($row->email ?? null) && $row->email !== '' ? mb_strtolower($row->email) : null;
        $phones = self::phoneForms(is_string($row->phone ?? null) ? $row->phone : '');
        $values = [trim("{$first} {$last}"), trim("{$last} {$first}"), (string) $email, (string) ($row->address ?? '')];

        if (is_string($row->id_value ?? null) && $row->id_value !== '') {
            try {
                $values[] = Crypt::decryptString($row->id_value);
            } catch (DecryptException) {
                // An ID number written under an older key: nothing to look for.
            }
        }

        $contact = is_string($row->emergency_contact ?? null) ? json_decode($row->emergency_contact, true) : null;

        if (is_array($contact)) {
            array_walk_recursive($contact, static function (mixed $value) use (&$values): void {
                if (is_string($value)) {
                    $values[] = $value;
                }
            });
        }

        $userEmail = null;

        if (is_numeric($row->user_id ?? null)) {
            $account = DB::table('users')->where('id', (int) $row->user_id)->first(['email', 'phone']);
            $userEmail = is_string($account->email ?? null) ? mb_strtolower($account->email) : null;
            $phones = [...$phones, ...self::phoneForms(is_string($account->phone ?? null) ? $account->phone : '')];
            $values[] = (string) $userEmail;
        }

        $known = array_values(array_unique(array_filter(
            [...$values, ...$phones],
            // Short values (a two-letter first name alone) would erase unrelated words.
            static fn (string $value): bool => mb_strlen(trim($value)) >= 4,
        )));
        usort($known, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return new self(
            organizationId: $organizationId,
            tenantProfileId: $tenantProfileId,
            userId: is_numeric($row->user_id ?? null) ? (int) $row->user_id : null,
            emails: array_values(array_unique(array_filter([$email, $userEmail]))),
            phones: array_values(array_unique($phones)),
            knownValues: $known,
        );
    }

    /**
     * "+2348031234567" is also written "2348031234567" and "08031234567".
     *
     * @return list<string>
     */
    private static function phoneForms(string $phone): array
    {
        $phone = trim($phone);

        if ($phone === '') {
            return [];
        }

        if (preg_match('/^\+234(\d{10})$/', $phone, $match) === 1) {
            return [$phone, '234'.$match[1], '0'.$match[1]];
        }

        return [$phone];
    }
}
