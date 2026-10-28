<?php

declare(strict_types=1);

namespace Modules\Payments\Credit;

use App\Models\User;
use App\Support\Money;
use Modules\Payments\Contracts\HandoverCredits;
use Modules\Payments\Models\TenantCreditBalance;

/** Contracts\HandoverCredits through the one writer of tenant credit (TenantCredit). */
final readonly class HandoverCreditRecords implements HandoverCredits
{
    public function __construct(private TenantCredit $credit) {}

    public function balances(array $tenantProfileIds): array
    {
        if ($tenantProfileIds === []) {
            return [];
        }

        $balances = [];

        foreach (TenantCreditBalance::query()->whereIn('tenant_profile_id', $tenantProfileIds)->orderBy('tenant_profile_id')->get() as $balance) {
            if ($balance->balance_kobo->isPositive()) {
                $balances[$balance->tenant_profile_id] = $balance->balance_kobo;
            }
        }

        return $balances;
    }

    public function close(int $tenantProfileId, Money $amount, bool $refunded, ?User $by, string $note): void
    {
        $this->credit->closeForHandover($tenantProfileId, $amount, $refunded, $by, $note);
    }

    public function open(int $tenantProfileId, Money $amount, ?User $by, string $note): void
    {
        $this->credit->addBroughtOver($tenantProfileId, $amount, $by, $note);
    }
}
