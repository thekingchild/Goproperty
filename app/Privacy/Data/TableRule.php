<?php

declare(strict_types=1);

namespace App\Privacy\Data;

use Closure;
use Illuminate\Database\Query\Builder;

/**
 * How the NDPA tooling treats one table that carries `organization_id`
 * (P09-02; PersonalDataMap):
 *
 * - `person`: narrows a query on the table (already limited to the
 *   person's organization) to the person's rows, or null with
 *   `notAboutTenant` saying why a tenant's export leaves the table out;
 * - `erase`: what an erasure does to the person's rows (keeping amounts,
 *   dates and numbers the law requires; docs/plans/P09-subtasks.md
 *   defaults 14–20);
 * - `notInOrganizationExport`: why the organization's export leaves the
 *   table out (null: exported whole);
 * - `masked`: encrypted columns, exported as their last four characters;
 *   `omitted`: never exported (secrets; columns named like a password,
 *   secret or token are omitted anyway);
 * - `keptOnPurge`: why an organization's purge keeps the table's rows
 *   (GoProperty's own records), or null: purged.
 */
final readonly class TableRule
{
    /**
     * @param  (Closure(Builder, Person, PersonSelection): mixed)|null  $person
     * @param  (Closure(Eraser): mixed)|null  $erase
     * @param  list<string>  $masked
     * @param  list<string>  $omitted
     * @param  (Closure(Builder, Person, PersonSelection): mixed)|null  $alsoErased  rows that are not the person's (not exported) but mention them, which the erasure cleans too
     */
    private function __construct(
        public ?Closure $person,
        public ?string $notAboutTenant,
        public ?Closure $erase,
        public ?string $notInOrganizationExport = null,
        public array $masked = [],
        public array $omitted = [],
        public ?string $keptOnPurge = null,
        public ?Closure $alsoErased = null,
    ) {}

    /**
     * @param  Closure(Builder, Person, PersonSelection): mixed  $select
     * @param  Closure(Eraser): mixed  $erase
     */
    public static function person(Closure $select, Closure $erase): self
    {
        return new self($select, null, $erase);
    }

    public static function notPersonal(string $why): self
    {
        return new self(null, $why, null);
    }

    public function masked(string ...$columns): self
    {
        return new self($this->person, $this->notAboutTenant, $this->erase, $this->notInOrganizationExport, array_values($columns), $this->omitted, $this->keptOnPurge, $this->alsoErased);
    }

    public function omitted(string ...$columns): self
    {
        return new self($this->person, $this->notAboutTenant, $this->erase, $this->notInOrganizationExport, $this->masked, array_values($columns), $this->keptOnPurge, $this->alsoErased);
    }

    public function notInOrganizationExport(string $why): self
    {
        return new self($this->person, $this->notAboutTenant, $this->erase, $why, $this->masked, $this->omitted, $this->keptOnPurge, $this->alsoErased);
    }

    public function keptOnPurge(string $why): self
    {
        return new self($this->person, $this->notAboutTenant, $this->erase, $this->notInOrganizationExport, $this->masked, $this->omitted, $why, $this->alsoErased);
    }

    /** @param  Closure(Builder, Person, PersonSelection): mixed  $rows */
    public function alsoErased(Closure $rows): self
    {
        return new self($this->person, $this->notAboutTenant, $this->erase, $this->notInOrganizationExport, $this->masked, $this->omitted, $this->keptOnPurge, $rows);
    }

    /** Never exported: secrets, and columns named like one. */
    public function omits(string $column): bool
    {
        return in_array($column, $this->omitted, true) || preg_match('/password|secret|token|remember|recovery_codes/i', $column) === 1;
    }
}
