<?php

declare(strict_types=1);

namespace Modules\Import\Targets;

use Modules\Import\Contracts\ImportReportPanels;

/** The steps other modules offer on a committed import's report (a singleton). */
final class ReportPanelRegistry implements ImportReportPanels
{
    /** @var list<string> */
    private array $components = [];

    public function register(string $component): void
    {
        if (! in_array($component, $this->components, true)) {
            $this->components[] = $component;
        }
    }

    public function all(): array
    {
        return $this->components;
    }
}
