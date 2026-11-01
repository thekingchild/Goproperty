<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\ComplianceFlags\Pages;

use App\Enums\ComplianceFlagStatus;
use App\Enums\ComplianceFlagType;
use App\Enums\TransactionTier;
use App\Platform\Compliance\ReviewFlag;
use App\Platform\Filament\Resources\ComplianceFlags\ComplianceFlagResource;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One compliance flag (P06-09, default 99): what was measured against what,
 * the payment or document it is about, the grace of a yearly-limit flag,
 * and who closed it and why.
 *
 * @property-read ReviewFlag $record
 */
final class ViewComplianceFlag extends ViewRecord
{
    protected static string $resource = ComplianceFlagResource::class;

    public function getTitle(): string
    {
        return $this->record->type->label().' — '.$this->record->customer->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            ComplianceFlagResource::acknowledgeAction()->record($this->record),
            ComplianceFlagResource::assignAction()->record($this->record),
            ComplianceFlagResource::extendGraceAction()->record($this->record),
            ComplianceFlagResource::resolveAction()->record($this->record),
            ComplianceFlagResource::dismissAction()->record($this->record),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Flag')->columns(3)->schema([
                TextEntry::make('customer.name')->label('Organization')
                    ->url(fn (ReviewFlag $record): string => CustomerResource::getUrl('view', ['record' => $record->organization_id])),
                TextEntry::make('type')->badge()
                    ->formatStateUsing(fn (ComplianceFlagType $state): string => $state->label())
                    ->color(fn (ComplianceFlagType $state): string => $state->color()),
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn (ComplianceFlagStatus $state): string => $state->label())
                    ->color(fn (ComplianceFlagStatus $state): string => $state->color()),
                TextEntry::make('tier')->formatStateUsing(fn (?TransactionTier $state): string => $state?->label() ?? '—')->placeholder('—'),
                TextEntry::make('metric_value_kobo')->label('Amount')
                    ->formatStateUsing(fn (?int $state): string => ComplianceFlagResource::amount($state))->placeholder('—'),
                TextEntry::make('limit_kobo')->label('Limit or line')
                    ->formatStateUsing(fn (?int $state): string => ComplianceFlagResource::amount($state))->placeholder('—'),
                TextEntry::make('about')->label('About')->columnSpanFull()->bulleted()
                    ->state(fn (ReviewFlag $record): array => self::about($record)),
                TextEntry::make('created_at')->label('Raised')->dateTime(),
                TextEntry::make('owners_notified_at')->label('Owners told')->dateTime()->placeholder('Not told (compliance only)'),
                TextEntry::make('assignedTo.name')->label('Assigned to')->placeholder('—'),
            ]),
            Section::make('Grace period')
                ->visible(fn (ReviewFlag $record): bool => $record->type === ComplianceFlagType::Volume100)
                ->schema([
                    TextEntry::make('grace_ends_at')->label('Ends on')
                        ->state(fn (ReviewFlag $record): ?string => $record->grace_ends_at?->setTimezone('Africa/Lagos')->format('j F Y'))
                        ->helperText('The organization is restricted from this day if no upgrade application has been sent. Extending it is in the audit log.'),
                ]),
            Section::make('Closed')
                ->visible(fn (ReviewFlag $record): bool => ! $record->isOpen())
                ->columns(3)
                ->schema([
                    TextEntry::make('resolution_note')->label('Note')->columnSpanFull(),
                    TextEntry::make('resolvedBy.name')->label('By')->placeholder('The monitoring'),
                    TextEntry::make('resolved_at')->label('On')->dateTime()->placeholder('—'),
                ]),
        ]);
    }

    /** @return list<string> */
    private static function about(ReviewFlag $record): array
    {
        $details = $record->details ?? [];
        $lines = [];

        if ($record->subject_type === 'payment' && $record->subject_id !== null) {
            $lines[] = "Payment #{$record->subject_id}".(is_string($details['paid_on'] ?? null) ? ', paid '.CarbonImmutable::parse($details['paid_on'])->format('j M Y') : '').(is_string($details['method'] ?? null) ? " ({$details['method']})" : '');
        }

        if ($record->subject_type === 'compliance_document' && $record->subject_id !== null) {
            $lines[] = "Document #{$record->subject_id}".(is_string($details['expires_at'] ?? null) ? ', expires '.CarbonImmutable::parse($details['expires_at'])->format('j M Y') : '');
        }

        if ($record->tenant_profile_id !== null) {
            $lines[] = "Tenant #{$record->tenant_profile_id}";
        }

        if (is_array($details['payment_ids'] ?? null)) {
            $lines[] = 'Payments #'.implode(', #', $details['payment_ids']).' within '.($details['window_days'] ?? '?').' days';
        }

        if (($details['review'] ?? false) === true) {
            $lines[] = 'Above the amount Tier 3 single payments are reviewed from (no cap)';
        }

        if (is_string($details['payer_type'] ?? null)) {
            $lines[] = 'Payer: '.($details['payer_type'] === 'corporate' ? 'a company' : 'a person');
        }

        if (is_string($details['month'] ?? null)) {
            $lines[] = 'Month '.CarbonImmutable::parse($details['month'].'-01')->format('F Y').', against an average of '.ComplianceFlagResource::amount(is_int($details['average_kobo'] ?? null) ? $details['average_kobo'] : null).' over '.($details['months'] ?? '?').' months';
        }

        if (is_int($details['percent'] ?? null)) {
            $lines[] = "{$details['percent']} % of the yearly limit";
        }

        if (is_string($details['limit_lowered_on'] ?? null)) {
            $lines[] = 'The limit was lowered on '.CarbonImmutable::parse($details['limit_lowered_on'])->format('j M Y').': a longer grace';
        }

        return $lines !== [] ? $lines : ['—'];
    }
}
