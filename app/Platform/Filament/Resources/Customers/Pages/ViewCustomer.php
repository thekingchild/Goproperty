<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Customers\Pages;

use App\Enums\BillingCycle;
use App\Platform\Compliance\TierOverrides;
use App\Platform\Compliance\TierSettings;
use App\Platform\Customers\Customer;
use App\Platform\Customers\CustomerSmsCredits;
use App\Platform\Customers\CustomerSubscriptions;
use App\Platform\Customers\OrganizationSuspension;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Customers\CustomerResource;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's page in the console. Suspend and Reactivate are offered
 * by CustomerPolicy (`customers.suspend`) and carried out by
 * OrganizationSuspension, which re-checks, audits with the reason and
 * emails the org-owners. Adjust SMS credits and SMS allowance (P05-03,
 * `plans.manage`) go through CustomerSmsCredits, audited. Change plan
 * (`customers.change_plan`) and Cancel / Reactivate subscription
 * (`customers.suspend`) go through CustomerSubscriptions (P06-07a), which
 * re-checks and moves the subscription through SubscriptionService.
 * Set / End tier limits (`tiers.override`, P06-08) go through TierOverrides.
 *
 * @property-read Customer $record
 */
final class ViewCustomer extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->changePlanAction(), $this->cancelSubscriptionAction(), $this->reactivateSubscriptionAction(),
            $this->adjustSmsCreditsAction(), $this->smsAllowanceAction(), $this->setTierLimitsAction(), $this->endTierLimitsAction(),
            $this->suspendAction(), $this->reactivateAction(),
        ];
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

    /** Staff move a customer to any plan, custom and no longer offered ones included (P06-07a, default 50). */
    public function changePlanAction(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('changePlan'))
            ->modalHeading(fn (): string => 'Change the plan of '.$this->customer()->name)
            ->modalDescription('At once, at the plan\'s current terms; the current invoice is not changed. Limits are not checked: an organization over one keeps everything and adds nothing until it is under it. The owners are not emailed.')
            ->fillForm(fn (): array => [
                'plan_id' => $this->customer()->subscription?->plan_id,
                'billing_cycle' => $this->customer()->subscription?->billing_cycle->value,
            ])
            ->schema([
                Select::make('plan_id')->label('Plan')->required()
                    ->options(fn (): array => app(CustomerSubscriptions::class)->planOptions()),
                Radio::make('billing_cycle')->label('Billing')->required()
                    ->options(collect(BillingCycle::cases())->mapWithKeys(fn (BillingCycle $cycle): array => [$cycle->value => $cycle->label()])->all()),
                Textarea::make('reason')->label('Reason')->required()
                    ->minLength(CustomerSubscriptions::REASON_MIN)->maxLength(CustomerSubscriptions::REASON_MAX)
                    ->helperText('Kept in the audit log with your name; not sent to the customer.'),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $planId = $data['plan_id'] ?? null;
                    $subscription = app(CustomerSubscriptions::class)->changePlan(
                        self::actor(),
                        $this->customer()->organization(),
                        is_numeric($planId) ? (int) $planId : 0,
                        BillingCycle::tryFrom(is_string($data['billing_cycle'] ?? null) ? $data['billing_cycle'] : '') ?? BillingCycle::Monthly,
                        (string) ($data['reason'] ?? ''),
                    );

                    return $this->customer()->name.' is now on '.$subscription->plan->name.', billed '.mb_strtolower($subscription->billing_cycle->label()).'.';
                });
                $this->reloadCustomer();
            });
    }

    /**
     * Custom Transaction Tier limits for this organization (P06-08, default
     * 79): a yearly and/or single-payment limit, a reason and an end day.
     */
    public function setTierLimitsAction(): Action
    {
        $naira = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->prefix('₦')
            ->integer()->minValue(1)->maxValue(intdiv(TierSettings::MAX_KOBO, 100))->helperText('Empty keeps the tier\'s limit.');

        return Action::make('setTierLimits')
            ->label('Set tier limits')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('overrideTierLimits'))
            ->modalHeading(fn (): string => 'Custom Transaction Tier limits for '.$this->customer()->name)
            ->modalDescription('They apply instead of the tier\'s limits until the end day, and replace custom limits already set. No approval is needed; the owners are not emailed.')
            ->fillForm(function (): array {
                $override = CustomerResource::tierLimits($this->customer())->override;

                return [
                    'annual' => $override?->annual_volume_limit_kobo !== null ? intdiv($override->annual_volume_limit_kobo, 100) : null,
                    'single' => $override?->single_payment_limit_kobo !== null ? intdiv($override->single_payment_limit_kobo, 100) : null,
                    'until' => TierOverrides::firstEndDay()->addMonthsNoOverflow(3)->toDateString(),
                ];
            })
            ->schema([
                $naira('annual', 'Yearly collection limit'),
                $naira('single', 'Single-payment limit'),
                DatePicker::make('until')->label('Until')->required()->native(false)
                    ->minDate(fn (): string => TierOverrides::firstEndDay()->toDateString())
                    ->maxDate(fn (): string => TierOverrides::lastEndDay()->toDateString())
                    ->helperText('The tier\'s limits apply again from this day (Lagos). At most a year ahead.'),
                Textarea::make('reason')->label('Reason')->required()
                    ->minLength(TierOverrides::REASON_MIN)->maxLength(TierOverrides::REASON_MAX)
                    ->helperText('Kept with the limits and in the audit log; not sent to the customer.'),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $kobo = static fn (mixed $naira): ?int => is_numeric($naira) ? Money::naira((int) $naira)->kobo : null;
                    $override = app(TierOverrides::class)->set(
                        self::actor(),
                        $this->customer()->organization(),
                        $kobo($data['annual'] ?? null),
                        $kobo($data['single'] ?? null),
                        (string) ($data['reason'] ?? ''),
                        (string) ($data['until'] ?? ''),
                    );

                    return 'Custom limits set for '.$this->customer()->name.' until '.$override->expires_at?->setTimezone('Africa/Lagos')->format('j M Y').'.';
                });
                $this->reloadCustomer();
            });
    }

    public function endTierLimitsAction(): Action
    {
        return Action::make('endTierLimits')
            ->label('End tier limits')
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('overrideTierLimits'))
            ->visible(fn (): bool => CustomerResource::tierLimits($this->customer())->hasOverride())
            ->requiresConfirmation()
            ->modalDescription('The tier\'s own limits apply again from now. The custom limits stay in the History.')
            ->schema([
                Textarea::make('reason')->label('Reason (optional)')->maxLength(TierOverrides::REASON_MAX),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $reason = $data['reason'] ?? null;
                    app(TierOverrides::class)->end(self::actor(), $this->customer()->organization(), is_string($reason) ? $reason : null);

                    return 'The tier\'s limits apply again to '.$this->customer()->name.'.';
                });
                $this->reloadCustomer();
            });
    }

    /** Cancel from any status but cancelled (P06-07a, default 52): the organization is closed, its data kept. */
    public function cancelSubscriptionAction(): Action
    {
        return Action::make('cancelSubscription')
            ->label('Cancel subscription')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize(fn (): bool => $this->allows('cancelSubscription'))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Cancel the subscription of '.$this->customer()->name.'?')
            ->modalDescription('No further invoice is issued. From their next request its members can sign in, view and export, but cannot change anything. Nothing is deleted. The org-owners are emailed.')
            ->schema($this->subscriptionReasonFields())
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(CustomerSubscriptions::class)->cancel(
                        self::actor(),
                        $this->customer()->organization(),
                        (string) ($data['reason'] ?? ''),
                        isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null,
                    );

                    return 'The subscription of '.$this->customer()->name.' is cancelled.';
                });
                $this->reloadCustomer();
            });
    }

    /** Only a cancelled subscription (P06-07a, default 52): billed again from today, the organization reopened if the subscription closed it. */
    public function reactivateSubscriptionAction(): Action
    {
        return Action::make('reactivateSubscription')
            ->label('Reactivate subscription')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->authorize(fn (): bool => $this->allows('reactivateSubscription'))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Reactivate the subscription of '.$this->customer()->name.'?')
            ->modalDescription('It becomes active at once and is invoiced again (the period due, from today when months were missed), then follows the usual reminders. The organization reopens if the subscription closed it; a suspension by staff stays. The org-owners are emailed.')
            ->schema($this->subscriptionReasonFields())
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(CustomerSubscriptions::class)->reactivate(
                        self::actor(),
                        $this->customer()->organization(),
                        (string) ($data['reason'] ?? ''),
                        isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null,
                    );

                    return 'The subscription of '.$this->customer()->name.' is active again.';
                });
                $this->reloadCustomer();
            });
    }

    /** @return list<Textarea> */
    private function subscriptionReasonFields(): array
    {
        return [
            Textarea::make('reason')->label('Reason')->required()
                ->minLength(CustomerSubscriptions::REASON_MIN)->maxLength(CustomerSubscriptions::REASON_MAX)
                ->helperText('Kept in the audit log with your name; not sent to the customer.'),
            Textarea::make('note_to_owner')
                ->label('Note to the organization owner (optional)')
                ->helperText('Quoted in the email to the org-owners. Leave out anything they must not see.')
                ->maxLength(CustomerSubscriptions::NOTE_MAX),
        ];
    }

    public function adjustSmsCreditsAction(): Action
    {
        return Action::make('adjustSmsCredits')
            ->label('Adjust SMS credits')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('manageSmsCredits'))
            ->modalHeading(fn (): string => 'Adjust the SMS credits of '.$this->customer()->name)
            ->modalDescription('Added credits never expire. Removed credits come out of the purchased ones first, never below zero.')
            ->schema([
                TextInput::make('credits')->label('Credits')->integer()->required()->minValue(-1_000_000)->maxValue(1_000_000)
                    ->helperText('For example 200 to add, -200 to remove.'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(300)
                    ->helperText('Shown to the customer in their credit history and kept in the audit log with your name.'),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $balance = app(CustomerSmsCredits::class)->adjust(self::actor(), $this->customer()->organization(), (int) ($data['credits'] ?? 0), (string) ($data['reason'] ?? ''));

                    return 'SMS credits adjusted. The balance is now '.number_format($balance).'.';
                });
                $this->reloadCustomer();
            });
    }

    public function smsAllowanceAction(): Action
    {
        return Action::make('smsAllowance')
            ->label('SMS allowance')
            ->icon('heroicon-o-calendar')
            ->color('gray')
            ->authorize(fn (): bool => $this->allows('manageSmsCredits'))
            ->modalHeading(fn (): string => 'Monthly SMS allowance of '.$this->customer()->name)
            ->modalDescription('Leave it empty to use the plan\'s allowance. A bigger allowance adds the difference this month; a smaller one applies from next month.')
            ->fillForm(fn (): array => ['allowance' => app(CustomerSmsCredits::class)->customAllowance($this->customer()->organization())])
            ->schema([
                TextInput::make('allowance')->label('Credits a month')->integer()->minValue(0)->maxValue(1_000_000),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    $value = $data['allowance'] ?? null;
                    app(CustomerSmsCredits::class)->setAllowance(self::actor(), $this->customer()->organization(), is_numeric($value) ? (int) $value : null);

                    return is_numeric($value) ? 'The monthly SMS allowance is now '.number_format((int) $value).'.' : 'The plan\'s SMS allowance applies again.';
                });
                $this->reloadCustomer();
            });
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
