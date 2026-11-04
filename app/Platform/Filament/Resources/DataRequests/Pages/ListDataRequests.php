<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\DataRequests\Pages;

use App\Models\DataRequest;
use App\Models\Organization;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\DataRequests\DataRequestResource;
use App\Platform\Privacy\PrivacyLookups;
use App\Privacy\DataRequests;
use App\Privacy\Erasure\RetentionRules;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The request forms (P09-02): the organization (searched by name, slug or
 * number), the whole organization or one of its tenants (searched by name,
 * phone, email or number), and the request's reference and lawful reason,
 * which stays on the request and never reaches the audit log.
 */
final class ListDataRequests extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = DataRequestResource::class;

    protected ?string $subheading = 'Only on a request whose identity you have checked (docs/runbooks/breach-response.md has the request checklist). Exports are ready in minutes and kept 7 days. Erasures need a second staff member\'s approval, then wait until 90 days after the tenancy or subscription ended.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export data')
                ->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (): bool => self::actor()->can('create', DataRequest::class))
                ->schema($this->requestForm())
                ->action(fn (array $data): bool => self::attempt(function () use ($data): string {
                    $request = app(DataRequests::class)->export($this->organization($data), $this->tenant($data), (string) $data['reason'], self::actor());

                    return "Export #{$request->id} is being made: it appears here to download when it is ready.";
                })),
            Action::make('erase')
                ->label('Erase data')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->authorize(fn (): bool => self::actor()->can('create', DataRequest::class))
                ->schema([
                    ...$this->requestForm(),
                    Checkbox::make('understood')->label('I understand: once carried out this cannot be undone. Amounts, dates and receipt numbers are kept; the person\'s details and files are not.')->accepted(),
                ])
                ->modalDescription('A tenant is erased only with no active lease and no unpaid bill, '.RetentionRules::DAYS.' days after their last lease ended; an organization is purged only once cancelled or deleted, '.RetentionRules::DAYS.' days after.')
                ->action(fn (array $data): bool => self::attempt(function () use ($data): string {
                    $request = app(DataRequests::class)->erasure($this->organization($data), $this->tenant($data), (string) $data['reason'], self::actor());

                    return "Erasure #{$request->id} is waiting for a second staff member's approval in Staff → Approvals.";
                })),
        ];
    }

    /** @return list<mixed> */
    private function requestForm(): array
    {
        $lookups = app(PrivacyLookups::class);

        return [
            Select::make('organization_id')->label('Organization')->required()->searchable()->live()
                ->getSearchResultsUsing(fn (string $search): array => $lookups->organizations($search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $lookups->organizationLabel($value)),
            Radio::make('scope')->label('Whose data')->required()->default('tenant')->live()
                ->options(['tenant' => 'One tenant of the organization', 'organization' => 'The whole organization']),
            Select::make('tenant_profile_id')->label('Tenant')->searchable()
                ->required(fn (Get $get): bool => $get('scope') === 'tenant')
                ->visible(fn (Get $get): bool => $get('scope') === 'tenant')
                ->getSearchResultsUsing(fn (string $search, Get $get): array => $lookups->tenants($get('organization_id'), $search))
                ->getOptionLabelUsing(fn (mixed $value): ?string => $lookups->tenantLabel($value)),
            Textarea::make('reason')->label('Reference and why')->required()->minLength(10)->maxLength(500)->rows(3)
                ->helperText('The ticket or letter, who asked and how their identity was checked. Use the reference, not the person\'s name: it is kept with the request.'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function organization(array $data): Organization
    {
        return Organization::withTrashed()->findOrFail((int) $data['organization_id']);
    }

    /** @param  array<string, mixed>  $data */
    private function tenant(array $data): ?int
    {
        return ($data['scope'] ?? 'tenant') === 'tenant' ? (int) $data['tenant_profile_id'] : null;
    }
}
