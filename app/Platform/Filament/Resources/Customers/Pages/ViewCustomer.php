<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\Pages;

use App\Platform\Customers\Customer;
use App\Platform\Customers\OrganizationSuspension;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's page in the console. Suspend and Reactivate are offered
 * by CustomerPolicy (`customers.suspend`) and carried out by
 * OrganizationSuspension, which re-checks, audits with the reason and
 * emails the org-owners.
 *
 * @property-read Customer $record
 */
final class ViewCustomer extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->suspendAction(), $this->reactivateAction()];
    }

    private function customer(): Customer
    {
        return $this->record;
    }

    private function allows(string $ability): bool
    {
        return Gate::forUser(self::actor())->allows($ability, $this->customer());
    }

    private function reloadCustomer(): void
    {
        $this->record = $this->customer()->fresh() ?? $this->customer();
    }

    /** @return list<Textarea> */
    private function reasonFields(string $reasonHelp): array
    {
        return [
            Textarea::make('reason')
                ->label('Reason')
                ->helperText($reasonHelp)
                ->required()
                ->maxLength(OrganizationSuspension::REASON_MAX),
            Textarea::make('note_to_owner')
                ->label('Note to the organization owner (optional)')
                ->helperText('Quoted in the email to the org-owners. Leave out anything they must not see.')
                ->maxLength(OrganizationSuspension::NOTE_MAX),
        ];
    }

    public function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->authorize(fn (): bool => $this->allows('suspend'))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Suspend '.$this->customer()->name.'?')
            ->modalDescription('From their next request its members can sign in, view and export, but cannot change anything. Nothing is deleted. The org-owners are emailed.')
            ->schema($this->reasonFields('Kept in the audit log with your name; not sent to the customer.'))
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $organization = app(OrganizationSuspension::class)->suspend(
                        self::actor(),
                        $this->customer()->organization(),
                        (string) ($data['reason'] ?? ''),
                        isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null,
                    );

                    return "{$organization->name} is suspended.";
                });
                $this->reloadCustomer();
            });
    }

    public function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-lock-open')
            ->color('success')
            ->authorize(fn (): bool => $this->allows('reactivate'))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Reactivate '.$this->customer()->name.'?')
            ->modalDescription(fn (): string => 'Its members can work again from their next request. It returns to '
                .($this->customer()->status_before_suspension?->label() ?? 'Active').'. The org-owners are emailed.')
            ->schema($this->reasonFields('Kept in the audit log with your name; not sent to the customer.'))
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $organization = app(OrganizationSuspension::class)->reactivate(
                        self::actor(),
                        $this->customer()->organization(),
                        (string) ($data['reason'] ?? ''),
                        isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null,
                    );

                    return "{$organization->name} is {$organization->status->value} again.";
                });
                $this->reloadCustomer();
            });
    }
}
