<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * Steps other modules offer on an import's report once it is committed
 * (DATA_IMPORT.md § 2.6): "Invite tenants now" (the Tenants module,
 * P03-13a). Each is a Livewire component, mounted with `batchId`, that
 * checks its own permissions; the Import module only shows it, never what
 * it does. Nothing on a panel runs on its own: the agency chooses.
 */
interface ImportReportPanels
{
    /** A Livewire component name, such as `tenants::import-invitations`. */
    public function register(string $component): void;

    /** @return list<string> the components, in the order they were registered */
    public function all(): array;
}
