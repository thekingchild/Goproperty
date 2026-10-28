<?php

declare(strict_types=1);

namespace Modules\Expenses\Http\Controllers;

use App\Media\Media;
use App\Media\MediaUrls;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Models\Expense;
use Modules\Expenses\Models\ExpenseReceipt;

/**
 * An expense receipt's file (P03-12), for the members who may see the
 * expense (ExpensePolicy::viewReceiptFile: never while staff impersonate):
 * a redirect to a short-lived signed link (App\Media\MediaUrls;
 * ARCHITECTURE.md § 9), made only after the policy check. thumb() gives an
 * image's thumbnail once the `media` queue made it, else the original; a
 * PDF has none. Archived receipts, and an archived expense's, keep their
 * file (evidence) and still open.
 *
 * {expense} and {receipt} resolve inside the organization only (the
 * `organization` middleware runs first), so another organization's answer
 * 404; so does a receipt of another expense.
 */
final class ExpenseReceiptFileController
{
    public function show(Organization $organization, Expense $expense, ExpenseReceipt $receipt, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewReceiptFile', $expense);

        return redirect()->away($urls->temporary($this->media($expense, $receipt)));
    }

    public function thumb(Organization $organization, Expense $expense, ExpenseReceipt $receipt, MediaUrls $urls): RedirectResponse
    {
        Gate::authorize('viewReceiptFile', $expense);
        $media = $this->media($expense, $receipt);
        abort_unless(str_starts_with((string) $media->mime_type, 'image/'), 404);

        return redirect()->away($urls->preview($media, 'thumb'));
    }

    private function media(Expense $expense, ExpenseReceipt $receipt): Media
    {
        abort_unless($receipt->expense_id === $expense->id, 404);
        $media = Media::query()->find($receipt->media_id);
        abort_unless($media instanceof Media, 404);

        return $media;
    }
}
