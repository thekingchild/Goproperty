<?php

declare(strict_types=1);

namespace Modules\Collections\Statements;

use App\Models\OrganizationBankAccount;

/**
 * What the upload page shows before anything is stored: the account, the
 * statement's header (its row and column names), the mapping offered (the
 * account's saved one, or one worked out from the headers) and the first
 * rows under the header, so the member can check the columns.
 */
final readonly class StatementPreview
{
    /**
     * @param  list<list<string>>  $sample  the first rows under the header, as text
     */
    public function __construct(
        public OrganizationBankAccount $account,
        public StatementLayout $layout,
        public StatementMapping $mapping,
        public array $sample,
        public bool $savedMappingUsed,
    ) {}
}
