<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Which rows of each table are the person's (P09-02): every rule of the
 * PersonalDataMap run in its order, inside the person's organization only
 * (`organization_id` = theirs on every query), so a rule can build on the
 * rows already found (a lease's deposit entries, a bill's items).
 */
final class PersonSelection
{
    /** @var array<string, list<int>> */
    private array $ids = [];

    /** @var array<string, list<int>> rows an erasure cleans besides the person's own */
    private array $alsoErased = [];

    public function __construct(public readonly Person $person) {}

    public static function of(Person $person, PersonalDataMap $map): self
    {
        $selection = new self($person);

        foreach ($map->rules() as $table => $rule) {
            if ($rule->person === null) {
                continue;
            }

            $query = DB::table($table)->where('organization_id', $person->organizationId);
            ($rule->person)($query, $person, $selection);
            $selection->ids[$table] = array_values(array_map(intval(...), $query->orderBy('id')->pluck('id')->all()));

            if ($rule->alsoErased !== null) {
                $also = DB::table($table)->where('organization_id', $person->organizationId);
                ($rule->alsoErased)($also, $person, $selection);
                $selection->alsoErased[$table] = array_values(array_map(intval(...), $also->orderBy('id')->pluck('id')->all()));
            }
        }

        return $selection;
    }

    /** @return list<int> */
    public function ids(string $table): array
    {
        if (! array_key_exists($table, $this->ids)) {
            throw new LogicException("The person's rows of {$table} are not known yet: put its rule before the rules that use it.");
        }

        return $this->ids[$table];
    }

    /**
     * The rows an erasure works on: the person's, and those that only mention them.
     *
     * @return list<int>
     */
    public function erased(string $table): array
    {
        return array_values(array_unique([...$this->ids($table), ...($this->alsoErased[$table] ?? [])]));
    }

    /** @return array<string, list<int>> */
    public function all(): array
    {
        return $this->ids;
    }
}
