<?php

declare(strict_types=1);

namespace Modules\Collections\Enums;

use App\Enums\Concerns\HasValues;

/**
 * What a statement column can hold (ONBOARDING_COMPLIANCE.md § 4.1). The
 * date and the narration are always needed; the money comes either as
 * separate credit and debit columns (most Nigerian bank exports) or as one
 * amount column, signed (negative = debit) or beside a Dr/Cr column. The
 * running balance, the bank's reference and the other party's name are
 * read when the export has them.
 *
 * Aliases are header names as Nigerian banks write them, compared after
 * normalising (letters and digits only, lower case), so "Trans. Date",
 * "TRANS DATE" and "Trans_Date" are one.
 */
enum StatementField: string
{
    use HasValues;

    case Date = 'date';
    case Narration = 'narration';
    case Credit = 'credit';
    case Debit = 'debit';
    case Amount = 'amount';
    case Direction = 'direction';
    case Balance = 'balance';
    case Reference = 'reference';
    case Counterparty = 'counterparty';

    public function label(): string
    {
        return match ($this) {
            self::Date => 'Transaction date',
            self::Narration => 'Narration (description)',
            self::Credit => 'Credit (money in)',
            self::Debit => 'Debit (money out)',
            self::Amount => 'Amount (one column for both)',
            self::Direction => 'Dr / Cr (beside the amount)',
            self::Balance => 'Balance',
            self::Reference => 'Bank reference',
            self::Counterparty => 'Sender or beneficiary',
        };
    }

    /**
     * Normalised header names, most specific first.
     *
     * @return list<string>
     */
    public function aliases(): array
    {
        return match ($this) {
            self::Date => ['transdate', 'transactiondate', 'trandate', 'txndate', 'trxdate', 'postingdate', 'postdate', 'posteddate', 'bookingdate', 'entrydate', 'transactiondatetime', 'date', 'transdatetime', 'datetime'],
            self::Narration => ['narration', 'narrative', 'description', 'transactiondescription', 'transactiondetails', 'details', 'remarks', 'remark', 'particulars', 'memo', 'transactionnarration', 'narrations'],
            self::Credit => ['credit', 'credits', 'creditamount', 'creditngn', 'deposit', 'deposits', 'lodgement', 'lodgements', 'lodgment', 'moneyin', 'paidin', 'inflow', 'cr', 'creditamt'],
            self::Debit => ['debit', 'debits', 'debitamount', 'debitngn', 'withdrawal', 'withdrawals', 'moneyout', 'paidout', 'outflow', 'dr', 'debitamt'],
            self::Amount => ['amount', 'transactionamount', 'amountngn', 'txnamount', 'tranamount', 'amt'],
            self::Direction => ['drcr', 'crdr', 'dc', 'cd', 'direction', 'debitcredit', 'creditdebit', 'type', 'transactiontype', 'trantype', 'txntype'],
            self::Balance => ['balance', 'runningbalance', 'closingbalance', 'availablebalance', 'ledgerbalance', 'bal', 'balancengn', 'bookbalance'],
            self::Reference => ['reference', 'ref', 'refno', 'referenceno', 'referencenumber', 'transactionreference', 'transactionref', 'tranid', 'transactionid', 'txnid', 'sessionid', 'chequeno', 'chqno', 'docnum', 'documentnumber', 'instrumentno'],
            self::Counterparty => ['counterparty', 'beneficiary', 'beneficiaryname', 'sender', 'sendername', 'remitter', 'originator', 'payer', 'payername', 'otherparty'],
        };
    }

    /** Header words that point at the field when no alias matches exactly. */
    public function hint(): ?string
    {
        return match ($this) {
            self::Date => 'date',
            self::Narration => 'narration',
            self::Credit => 'credit',
            self::Debit => 'debit',
            self::Balance => 'balance',
            self::Reference => 'reference',
            default => null,
        };
    }

    public function required(): bool
    {
        return $this === self::Date || $this === self::Narration;
    }

    /** Header text as compared: letters and digits only, lower case. */
    public static function normalise(string $header): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($header));
    }
}
