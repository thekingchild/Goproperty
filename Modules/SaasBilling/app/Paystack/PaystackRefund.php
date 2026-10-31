<?php

declare(strict_types=1);

namespace Modules\SaasBilling\Paystack;

/**
 * A refund as GoProperty's Paystack reports it (POST /refund, GET
 * /refund/{id}; P06-07b): its id and status (`pending`, `processing`,
 * `processed`, `failed`, …, lower-cased) and the amount in kobo.
 */
final readonly class PaystackRefund
{
    public function __construct(
        public string $id,
        public string $status,
        public ?int $amount,
    ) {}

    public static function from(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;

        if ((! is_int($id) && ! is_string($id)) || (string) $id === '' || ! is_string($status)) {
            return null;
        }

        return new self(mb_substr((string) $id, 0, 40), strtolower($status), is_int($amount) ? $amount : null);
    }

    public function processed(): bool
    {
        return $this->status === 'processed';
    }

    public function failed(): bool
    {
        return in_array($this->status, ['failed', 'reversed'], true);
    }
}
