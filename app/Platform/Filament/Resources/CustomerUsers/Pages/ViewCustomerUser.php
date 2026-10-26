<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\CustomerUsers\Pages;

use App\Models\Organization;
use App\Models\User;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerUser;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\CustomerUsers\CustomerUserResource;
use App\Platform\Impersonation\ImpersonationMode;
use App\Platform\Impersonation\Impersonations;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * A customer user's page. "Impersonate" (PLATFORM_CONSOLE.md § 6) is
 * offered by CustomerUserPolicy (`impersonate.read_only` /
 * `impersonate.write`) and carried out by Impersonations, which checks
 * again and records refusals: a read-only session opens at once through
 * the one-time hand-off link; write mode first asks a second staff member
 * to approve, then "Start approved write session" opens it.
 *
 * @property-read CustomerUser $record
 */
final class ViewCustomerUser extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = CustomerUserResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->impersonateAction(), $this->startApprovedWriteAction()];
    }

    private function allows(string $ability): bool
    {
        return Gate::forUser(self::actor())->allows($ability, $this->record);
    }

    private function user(): User
    {
        return User::query()->whereKey($this->record->getKey())->firstOrFail();
    }

    public function impersonateAction(): Action
    {
        return Action::make('impersonate')
            ->label('Impersonate')
            ->icon('heroicon-o-eye')
            ->color('warning')
            ->authorize(fn (): bool => $this->allows('impersonate'))
            ->modalHeading(fn (): string => 'Impersonate '.$this->record->name)
            ->modalDescription('Opens the customer app as this user for at most 30 minutes, with a banner on every page. Money actions, bank and Paystack settings, billing transfers, sign-in details, members and deleting are always blocked. Everything you see and do is recorded in the impersonation log. The customer is not notified.')
            ->modalSubmitActionLabel('Start')
            ->schema([
                Select::make('organization')
                    ->label('Organization')
                    ->options(fn (): array => $this->record->organizations()->orderBy('name')->pluck('name', 'organizations.id')->all())
                    ->default(fn (): ?int => $this->record->organizations()->count() === 1 ? (int) $this->record->organizations()->value('organizations.id') : null)
                    ->required(),
                Radio::make('mode')
                    ->options(fn (): array => array_filter([
                        ImpersonationMode::ReadOnly->value => $this->allows('impersonateReadOnly') ? 'Read-only' : null,
                        ImpersonationMode::Write->value => $this->allows('impersonateWrite') ? 'Write (a second staff member must approve first)' : null,
                    ]))
                    ->default(fn (): string => $this->allows('impersonateReadOnly') ? ImpersonationMode::ReadOnly->value : ImpersonationMode::Write->value)
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('For example the support ticket number. Kept in the audit log with your name.')
                    ->required()
                    ->maxLength(Impersonations::REASON_MAX),
            ])
            ->action(function (array $data): void {
                $redirect = null;

                self::attempt(function () use ($data, &$redirect): ?string {
                    $organization = Organization::query()->findOrFail((int) ($data['organization'] ?? 0));
                    $mode = ImpersonationMode::from((string) ($data['mode'] ?? ''));
                    $reason = (string) ($data['reason'] ?? '');

                    if ($mode === ImpersonationMode::Write) {
                        app(Impersonations::class)->requestWrite(self::actor(), $this->user(), $organization, $reason);

                        return 'Sent for approval: a second staff member with write-mode impersonation must approve it in Approvals. Then start it here within an hour.';
                    }

                    $redirect = app(Impersonations::class)->start(self::actor(), $this->user(), $organization, $mode, $reason)->url;

                    return null;
                });

                if (is_string($redirect)) {
                    $this->redirect($redirect);
                }
            });
    }

    /** Opens the write-mode session a second staff member approved (once, within an hour). */
    public function startApprovedWriteAction(): Action
    {
        return Action::make('startApprovedWrite')
            ->label('Start approved write session')
            ->icon('heroicon-o-pencil-square')
            ->color('danger')
            ->visible(fn (): bool => $this->allows('impersonateWrite') && app(Impersonations::class)->approvedWriteRequest(self::actor(), (int) $this->record->getKey()) !== null)
            ->authorize(fn (): bool => $this->allows('impersonateWrite'))
            ->requiresConfirmation()
            ->modalDescription(function (): string {
                $approval = app(Impersonations::class)->approvedWriteRequest(self::actor(), (int) $this->record->getKey());
                $organization = Customer::query()->find((int) ($approval->payload['organization_id'] ?? 0));

                return 'Write mode in '.($organization->name ?? 'the organization').' for at most 30 minutes. Approved by '.($approval?->approvedBy->name ?? 'a second staff member').'. Reason: '.($approval->reason ?? '—');
            })
            ->action(function (): void {
                $redirect = null;

                self::attempt(function () use (&$redirect): ?string {
                    $approval = app(Impersonations::class)->approvedWriteRequest(self::actor(), (int) $this->record->getKey());

                    if ($approval === null) {
                        return 'There is no approved write-mode request to use.';
                    }

                    $organization = Organization::query()->findOrFail((int) ($approval->payload['organization_id'] ?? 0));
                    $redirect = app(Impersonations::class)->start(self::actor(), $this->user(), $organization, ImpersonationMode::Write, (string) $approval->reason, $approval)->url;

                    return null;
                });

                if (is_string($redirect)) {
                    $this->redirect($redirect);
                }
            });
    }
}
