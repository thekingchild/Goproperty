<?php

declare(strict_types=1);

namespace App\Platform\Compliance;

use App\Compliance\TierTerms;
use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Support\Money;

/**
 * A tier's terms as staff propose them (P06-08, docs/plans/P06-subtasks.md
 * default 72), in kobo; a null limit means no cap. Compared with the terms in
 * force to say what changes.
 */
final readonly class TierDraft
{
    /**
     * @param  list<ComplianceDocumentType>  $requiredDocuments
     * @param  list<ComplianceDocumentType>  $optionalDocuments
     */
    public function __construct(
        public TransactionTier $tier,
        public string $name,
        public ?int $annualLimitKobo,
        public ?int $singlePaymentLimitKobo,
        public ?int $reviewThresholdKobo,
        public array $requiredDocuments,
        public array $optionalDocuments,
        public ?int $reviewSlaDays,
    ) {}

    public static function of(TierTerms $terms): self
    {
        return new self(
            $terms->tier,
            $terms->name,
            $terms->annualLimit?->kobo,
            $terms->singlePaymentLimit?->kobo,
            $terms->reviewThreshold?->kobo,
            $terms->requiredDocuments,
            $terms->optionalDocuments,
            $terms->reviewSlaDays,
        );
    }

    /**
     * What the audit log keeps of the terms.
     *
     * @return array{name: string, annual_volume_limit_kobo: int|null, single_payment_limit_kobo: int|null, review_threshold_kobo: int|null, required_documents: list<string>, optional_documents: list<string>, review_sla_days: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'annual_volume_limit_kobo' => $this->annualLimitKobo,
            'single_payment_limit_kobo' => $this->singlePaymentLimitKobo,
            'review_threshold_kobo' => $this->reviewThresholdKobo,
            'required_documents' => self::values($this->requiredDocuments),
            'optional_documents' => self::values($this->optionalDocuments),
            'review_sla_days' => $this->reviewSlaDays,
        ];
    }

    /**
     * What changes from the terms in force, one short line each
     * ("Yearly limit: ₦50,000,000 → ₦60,000,000").
     *
     * @return list<string>
     */
    public function changesFrom(self $current): array
    {
        $changes = [];

        if ($this->name !== $current->name) {
            $changes[] = "Name: {$current->name} → {$this->name}";
        }

        foreach ([
            'Yearly limit' => [$current->annualLimitKobo, $this->annualLimitKobo, 'No cap'],
            'Single-payment limit' => [$current->singlePaymentLimitKobo, $this->singlePaymentLimitKobo, 'No cap'],
            'Review payments above' => [$current->reviewThresholdKobo, $this->reviewThresholdKobo, 'None'],
        ] as $label => [$from, $to, $none]) {
            if ($from !== $to) {
                $changes[] = "{$label}: ".self::amount($from, $none).' → '.self::amount($to, $none);
            }
        }

        foreach ([
            'Required' => [$current->requiredDocuments, $this->requiredDocuments],
            'Optional' => [$current->optionalDocuments, $this->optionalDocuments],
        ] as $label => [$from, $to]) {
            $added = array_udiff($to, $from, self::compare(...));
            $removed = array_udiff($from, $to, self::compare(...));

            if ($added !== []) {
                $changes[] = "{$label} documents added: ".self::labels($added);
            }

            if ($removed !== []) {
                $changes[] = "{$label} documents removed: ".self::labels($removed);
            }
        }

        if ($this->reviewSlaDays !== $current->reviewSlaDays) {
            $changes[] = 'Review target: '.self::days($current->reviewSlaDays).' → '.self::days($this->reviewSlaDays);
        }

        return $changes;
    }

    /**
     * The limits this draft lowers (a cap where there was none counts):
     * nobody is restricted at once (ONBOARDING_COMPLIANCE.md § 5.2, default 76).
     *
     * @return list<string>
     */
    public function lowers(self $current): array
    {
        $lowered = [];

        foreach ([
            'yearly limit' => [$current->annualLimitKobo, $this->annualLimitKobo],
            'single-payment limit' => [$current->singlePaymentLimitKobo, $this->singlePaymentLimitKobo],
        ] as $label => [$from, $to]) {
            if ($to !== null && ($from === null || $to < $from)) {
                $lowered[] = $label;
            }
        }

        return $lowered;
    }

    public static function amount(?int $kobo, string $none = 'No cap'): string
    {
        return $kobo === null ? $none : Money::kobo($kobo)->format();
    }

    public static function days(?int $days): string
    {
        return match ($days) {
            null => 'none (active straight away)',
            1 => '1 business day',
            default => "{$days} business days",
        };
    }

    /**
     * @param  list<ComplianceDocumentType>  $types
     * @return list<string>
     */
    private static function values(array $types): array
    {
        return array_map(static fn (ComplianceDocumentType $type): string => $type->value, $types);
    }

    /** @param  array<ComplianceDocumentType>  $types */
    private static function labels(array $types): string
    {
        return implode(', ', array_map(static fn (ComplianceDocumentType $type): string => $type->label(), $types));
    }

    private static function compare(ComplianceDocumentType $a, ComplianceDocumentType $b): int
    {
        return strcmp($a->value, $b->value);
    }
}
