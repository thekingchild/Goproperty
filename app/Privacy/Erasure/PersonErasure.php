<?php

declare(strict_types=1);

namespace App\Privacy\Erasure;

use App\Media\Media;
use App\Privacy\Data\Eraser;
use App\Privacy\Data\Person;
use App\Privacy\Data\PersonalDataMap;
use App\Privacy\Data\PersonSelection;
use App\Privacy\Data\Scrubber;
use Illuminate\Support\Facades\DB;

/**
 * Erases one tenant's personal data in one organization (P09-02; NDPA
 * 2023 s. 34; docs/plans/P09-subtasks.md defaults 13–17): each table's
 * erase rule in PersonalDataMap runs on the tenant's rows there: names,
 * contacts, addresses and ID numbers are blanked, text they wrote is
 * replaced, their identifying values are taken out of other text and of
 * issued documents' snapshots, and the files of their records (proofs,
 * receipts, attachments, contracts; evidence included) are purged inside
 * Media::purging(). Amounts, dates, receipt and bill numbers, statuses and
 * links stay: the financial records the law requires are kept, without
 * the person. The same person elsewhere (another organization, another
 * tenant with the same name) is untouched.
 *
 * The portal account is anonymised too when nothing outside this
 * organization still uses it (AccountAnonymiser).
 *
 * Safe to run again: the files go first (a rerun finds the same rows by
 * their ids and links), then every row change in one transaction.
 */
final readonly class PersonErasure
{
    public function __construct(
        private PersonalDataMap $map,
        private AccountAnonymiser $accounts,
    ) {}

    /** @return array<string, int> rows changed per table, and files purged */
    public function erase(int $organizationId, int $tenantProfileId): array
    {
        $person = Person::load($organizationId, $tenantProfileId);
        $selection = PersonSelection::of($person, $this->map);
        $counts = ['files' => $this->purgeFiles($selection->ids('media'))];

        DB::transaction(function () use ($person, $selection, &$counts): void {
            $scrubber = new Scrubber($person);

            // Last rule first: rows that point at others are cleaned before them.
            foreach (array_reverse($this->map->rules(), true) as $table => $rule) {
                if ($rule->erase === null || $table === 'media') {
                    continue;
                }

                $eraser = new Eraser($table, $selection->erased($table), $person, $scrubber);
                ($rule->erase)($eraser);
                $counts[$table] = $eraser->changed();
            }

            $counts['account'] = $person->userId !== null && $this->accounts->anonymiseIfUnused($person->userId, $person) ? 1 : 0;
            $counts['email_suppressions'] = $this->accounts->liftSuppressions($person->emails);
        }, 3);

        return array_filter($counts);
    }

    /** @param  list<int>  $ids */
    private function purgeFiles(array $ids): int
    {
        $purged = 0;

        Media::purging(function () use ($ids, &$purged): void {
            foreach (Media::query()->whereIn('id', $ids)->orderBy('id')->get() as $media) {
                // Spatie removes the original and every conversion from the disk.
                $media->delete();
                $purged++;
            }
        });

        return $purged;
    }
}
