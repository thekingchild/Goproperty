<?php

declare(strict_types=1);

namespace Modules\Payments\Lists;

use App\Models\User;
use Modules\Payments\Contracts\PaymentMethod;
use Modules\Payments\Models\Receipt;

/**
 * The names a batch of receipts shows: the tenant as printed on the receipt
 * (kept in `details` at issue) and the members who asked for and approved
 * a void, read with one query per batch (no query per receipt,
 * ARCHITECTURE.md § 13).
 */
final readonly class ReceiptRows
{
    /**
     * @param  iterable<Receipt>  $receipts
     * @return list<ReceiptRow>
     */
    public function rows(iterable $receipts): array
    {
        $receipts = is_array($receipts) ? array_values($receipts) : iterator_to_array($receipts, false);

        if ($receipts === []) {
            return [];
        }

        $userIds = [];

        foreach ($receipts as $receipt) {
            foreach ([$receipt->voided_by_user_id ?? $receipt->void_requested_by_user_id, $receipt->void_approved_by_user_id] as $id) {
                if ($id !== null) {
                    $userIds[$id] = $id;
                }
            }
        }

        $names = $userIds === [] ? [] : User::query()->whereIn('id', array_values($userIds))->pluck('name', 'id')->all();

        return array_map(static function (Receipt $receipt) use ($names): ReceiptRow {
            $tenant = is_array($receipt->details['tenant'] ?? null) ? $receipt->details['tenant'] : [];
            $payment = is_array($receipt->details['payment'] ?? null) ? $receipt->details['payment'] : [];
            $requester = $receipt->voided_by_user_id ?? $receipt->void_requested_by_user_id;
            $name = static fn (?int $id): ?string => $id !== null && is_string($names[$id] ?? null) ? $names[$id] : null;

            return new ReceiptRow(
                receipt: $receipt,
                tenantName: is_string($tenant['name'] ?? null) ? $tenant['name'] : (string) __('Unknown tenant'),
                method: (PaymentMethod::tryFrom(is_string($payment['method'] ?? null) ? $payment['method'] : '') ?? PaymentMethod::BankTransfer)->label(),
                requestedBy: $name($requester),
                approvedBy: $name($receipt->void_approved_by_user_id),
            );
        }, $receipts);
    }
}
