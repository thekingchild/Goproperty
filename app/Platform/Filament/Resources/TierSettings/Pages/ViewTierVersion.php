<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierSettings\Pages;

use App\Compliance\TransactionTiers;
use App\Enums\TierVersionStatus;
use App\Models\TransactionTierVersion;
use App\Platform\Compliance\TierDraft;
use App\Platform\Compliance\TierSettings;
use App\Platform\Filament\Resources\TierSettings\TierSettingsResource;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One version of a tier's terms (P06-08, docs/plans/P06-subtasks.md default
 * 81): everything it sets, who proposed and approved it and why, and, while
 * it waits or is scheduled, what it changes from the terms in force, so the
 * approver sees the change before deciding in Staff → Approvals.
 *
 * @property-read TransactionTierVersion $record
 */
final class ViewTierVersion extends ViewRecord
{
    protected static string $resource = TierSettingsResource::class;

    public function getTitle(): string
    {
        return $this->record->tier->label().' — version '.$this->record->version;
    }

    protected function getHeaderActions(): array
    {
        return [TierSettingsResource::proposeAction()->record($this->record), TierSettingsResource::cancelAction()->record($this->record)];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Terms')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('state')->badge()
                    ->state(fn (TransactionTierVersion $record): string => TierSettingsResource::state($record))
                    ->color(fn (string $state): string => TierSettingsResource::stateColor($state)),
                TextEntry::make('effective_from')->label('Applies from')->date('j F Y'),
                TextEntry::make('annual')->label('Yearly collection limit')
                    ->state(fn (TransactionTierVersion $record): string => TierDraft::amount($record->annual_volume_limit_kobo)),
                TextEntry::make('single')->label('Single-payment limit')
                    ->state(fn (TransactionTierVersion $record): string => TierDraft::amount($record->single_payment_limit_kobo)),
                TextEntry::make('review')->label('Single payments reviewed above')
                    ->state(fn (TransactionTierVersion $record): string => TierDraft::amount($record->review_threshold_kobo, 'None')),
                TextEntry::make('required')->label('Required documents')->columnSpan(2)
                    ->state(fn (TransactionTierVersion $record): string => TierSettingsResource::documents($record->requiredDocuments())),
                TextEntry::make('review_sla_days')->label('Review target')
                    ->state(fn (TransactionTierVersion $record): string => TierDraft::days($record->review_sla_days)),
                TextEntry::make('optional')->label('Optional documents')->columnSpan(3)
                    ->state(fn (TransactionTierVersion $record): string => TierSettingsResource::documents($record->optionalDocuments())),
            ]),
            Section::make('Compared with the terms in force')
                ->visible(fn (TransactionTierVersion $record): bool => $record->status === TierVersionStatus::Pending || $record->isScheduled())
                ->schema([
                    TextEntry::make('changes')->hiddenLabel()->bulleted()
                        ->state(fn (TransactionTierVersion $record): array => $this->changes($record)),
                    TextEntry::make('warnings')->label('Please note')->bulleted()->color('warning')
                        ->state(fn (TransactionTierVersion $record): array => app(TierSettings::class)->warnings($this->draft($record)))
                        ->visible(fn (TransactionTierVersion $record): bool => app(TierSettings::class)->warnings($this->draft($record)) !== []),
                ]),
            Section::make('Who and why')->columns(3)->schema([
                TextEntry::make('reason')->columnSpanFull()->placeholder('—'),
                TextEntry::make('proposedBy.name')->label('Proposed by')->placeholder('Launch values'),
                TextEntry::make('created_at')->label('Proposed')->dateTime(),
                TextEntry::make('approval')->label('Approval request')
                    ->state(fn (TransactionTierVersion $record): string => $record->approvalRequest === null ? '—' : ucfirst($record->approvalRequest->status->value).($record->approvalRequest->isPending() ? ', until '.$record->approvalRequest->expires_at?->setTimezone(TransactionTiers::TIMEZONE)->format('j M Y H:i') : '')),
                TextEntry::make('approvedBy.name')->label('Approved by')->placeholder('—'),
                TextEntry::make('approved_at')->label('Approved')
                    ->state(fn (TransactionTierVersion $record): ?CarbonImmutable => $record->approved_at)->dateTime()->placeholder('—'),
                TextEntry::make('cancelledBy.name')->label('Cancelled by')->placeholder('—')
                    ->visible(fn (TransactionTierVersion $record): bool => $record->status === TierVersionStatus::Cancelled),
            ]),
        ]);
    }

    private function draft(TransactionTierVersion $record): TierDraft
    {
        return new TierDraft(
            $record->tier,
            $record->name,
            $record->annual_volume_limit_kobo,
            $record->single_payment_limit_kobo,
            $record->review_threshold_kobo,
            $record->requiredDocuments(),
            $record->optionalDocuments(),
            $record->review_sla_days,
        );
    }

    /** @return list<string> */
    private function changes(TransactionTierVersion $record): array
    {
        $current = TierDraft::of(app(TransactionTiers::class)->terms($record->tier));
        $changes = $this->draft($record)->changesFrom($current);

        return $changes !== [] ? $changes : ['Nothing: these are the terms in force.'];
    }
}
