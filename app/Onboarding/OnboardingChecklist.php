<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\ChecklistItem;
use App\Enums\ChecklistStatus;
use App\Models\OnboardingChecklistItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The current organization's setup checklist (ONBOARDING_COMPLIANCE.md § 2
 * step 7), shown on the workspace dashboard until every item is done.
 * Works in OrganizationContext like every organization-owned model.
 *
 * Later features mark their item done when the work happens, e.g. the first
 * bank account (P01-05 / P03-07):
 *
 *     app(OnboardingChecklist::class)->complete(ChecklistItem::Collections, $user);
 *
 * An organization without checklist rows (one made before signup existed,
 * or by staff) has no checklist; complete() still records the item.
 */
final class OnboardingChecklist
{
    /** Every item, pending, for a new organization. Safe to run again. */
    public function start(): void
    {
        foreach (ChecklistItem::cases() as $item) {
            OnboardingChecklistItem::query()->firstOrCreate(['item' => $item->value], ['status' => ChecklistStatus::Pending]);
        }
    }

    /**
     * The organization's items in checklist order.
     *
     * @return Collection<int, OnboardingChecklistItem>
     */
    public function items(): Collection
    {
        $order = array_flip(ChecklistItem::values());

        return OnboardingChecklistItem::query()->get()
            ->sortBy(fn (OnboardingChecklistItem $row): int => $order[$row->item->value] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Marks an item done. Done items stay done (the first completion is
     * kept); details are merged in.
     *
     * @param  array<string, mixed>  $details
     */
    public function complete(ChecklistItem $item, ?User $by = null, array $details = []): void
    {
        $row = OnboardingChecklistItem::query()->firstOrNew(['item' => $item->value]);

        if (! $row->isDone()) {
            $row->status = ChecklistStatus::Done;
            $row->completed_at = now();
            $row->completed_by_user_id = $by?->id;
        }

        $row->details = array_merge($row->details ?? [], $details);
        $row->save();
    }

    /**
     * Puts an item back to pending, e.g. when a Tier 2 request replaces a
     * Tier 1 choice and now waits for documents.
     *
     * @param  array<string, mixed>  $details
     */
    public function reopen(ChecklistItem $item, array $details = []): void
    {
        $row = OnboardingChecklistItem::query()->firstOrNew(['item' => $item->value]);
        $row->status = ChecklistStatus::Pending;
        $row->completed_at = null;
        $row->completed_by_user_id = null;
        $row->details = array_merge($row->details ?? [], $details);
        $row->save();
    }

    public function isDone(ChecklistItem $item): bool
    {
        return OnboardingChecklistItem::query()->where('item', $item->value)->where('status', ChecklistStatus::Done)->exists();
    }

    /** Whether the dashboard should show the checklist: it has items and some are not done. */
    public function isOpen(): bool
    {
        return OnboardingChecklistItem::query()->where('status', ChecklistStatus::Pending)->exists();
    }
}
