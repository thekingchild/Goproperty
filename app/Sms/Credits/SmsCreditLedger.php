<?php

declare(strict_types=1);

namespace App\Sms\Credits;

use App\Exports\ExportColumn;
use App\Exports\QueryListExport;
use App\Models\SmsCreditEntry;
use App\Models\User;
use App\Sms\SmsText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Settings → SMS credits, the credit ledger (App\Livewire\Settings\SmsCreditsPage)
 * and its export (P05-03; AGENT.md § 5 lists): every entry, newest first.
 * Numbers texted are masked; no message text. SmsCreditPolicy::export.
 *
 * @extends QueryListExport<SmsCreditEntry>
 */
final class SmsCreditLedger extends QueryListExport
{
    public function key(): string
    {
        return 'sms_credit_entries';
    }

    public function title(): string
    {
        return 'SMS credits';
    }

    public function filters(array $input): array
    {
        return [];
    }

    public function authorize(User $user, array $filters): void
    {
        Gate::forUser($user)->authorize('export', SmsCreditEntry::class);
    }

    public function query(User $user, array $filters): Builder
    {
        return SmsCreditEntry::query()->with('delivery')->orderByDesc('id');
    }

    public function columns(User $user): array
    {
        return [
            ExportColumn::make('Date', fn (SmsCreditEntry $entry) => $entry->created_at),
            ExportColumn::make('Entry', fn (SmsCreditEntry $entry) => $entry->reason),
            ExportColumn::make('Credits', fn (SmsCreditEntry $entry): int => $entry->credits),
            ExportColumn::make('Pool', fn (SmsCreditEntry $entry) => $entry->pool),
            ExportColumn::make('Balance after', fn (SmsCreditEntry $entry): int => $entry->balance_after),
            ExportColumn::make('Details', fn (SmsCreditEntry $entry): ?string => self::details($entry)),
        ];
    }

    /** What the entry was for, in a few words: the SMS (masked number), the invoice, the month, the reason given. */
    public static function details(SmsCreditEntry $entry): ?string
    {
        $month = static fn (?string $month): string => $month !== null ? CarbonImmutable::createFromFormat('!Y-m', $month, SmsCredits::TIMEZONE)->format('F Y') : '';

        return match ($entry->reason) {
            CreditReason::Usage => $entry->delivery !== null
                ? Str::ucfirst(str_replace(['reminder.', '_'], ['', ' '], $entry->delivery->notification_type)).' reminder to '.SmsText::mask($entry->delivery->recipient_contact)
                : null,
            CreditReason::MonthlyAllowance => 'Allowance for '.$month($entry->month),
            CreditReason::Expiry => 'Unused allowance of '.$month($entry->month),
            CreditReason::Purchase, CreditReason::Adjustment => $entry->note,
        };
    }
}
