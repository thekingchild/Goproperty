<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\TierApplications\Pages;

use App\Compliance\RequiredDocuments;
use App\Models\TierApplication;
use App\Platform\Compliance\ReviewApplication;
use App\Platform\Compliance\TierReview;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\TierApplications\TierApplicationResource;
use App\Tenancy\OrganizationContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * A Transaction Tier application in the console. Start review and Request
 * more information need `tiers.review`; Approve and Reject `tiers.approve`
 * (TierReviewPolicy). TierReview carries them out: it re-checks, audits with
 * the reason and emails the org-owners. Approval moves the organization to
 * the tier asked for.
 *
 * @property-read ReviewApplication $record
 */
final class ViewTierApplication extends ViewRecord
{
    use ActsAsStaff;

    protected static string $resource = TierApplicationResource::class;

    public function getSubheading(): ?string
    {
        $application = $this->application();

        if (! $application->status->isOpen()) {
            return null;
        }

        $problems = app(OrganizationContext::class)->run($application->organization(), function () use ($application): array {
            $model = TierApplication::query()->find($application->id);

            return $model === null ? [] : app(RequiredDocuments::class)->problems($model);
        });

        return $problems === [] ? 'Every required document is in.' : 'Still missing: '.implode(' ', $problems);
    }

    protected function getHeaderActions(): array
    {
        return [$this->startReviewAction(), $this->requestMoreInfoAction(), $this->approveAction(), $this->rejectAction()];
    }

    private function application(): ReviewApplication
    {
        return $this->record;
    }

    private function allows(string $ability): bool
    {
        return Gate::forUser(self::actor())->allows($ability, $this->application());
    }

    private function reload(): void
    {
        $this->record = $this->application()->fresh() ?? $this->application();
    }

    public function startReviewAction(): Action
    {
        return Action::make('startReview')
            ->label('Start review')
            ->icon('heroicon-o-eye')
            ->authorize(fn (): bool => $this->allows('startReview'))
            ->requiresConfirmation()
            ->modalDescription('You become the reviewer. The organization can no longer change the application while you review it.')
            ->action(function (): void {
                self::attempt(function (): string {
                    app(TierReview::class)->startReview(self::actor(), $this->application());

                    return 'You are reviewing this application.';
                });
                $this->reload();
            });
    }

    public function requestMoreInfoAction(): Action
    {
        return Action::make('requestMoreInfo')
            ->label('Request more information')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->color('warning')
            ->authorize(fn (): bool => $this->allows('requestMoreInfo'))
            ->modalHeading('Ask the organization for more information')
            ->modalDescription('The application goes back to the organization until they answer. They see this text and are emailed.')
            ->schema([
                Textarea::make('request')
                    ->label('What do they need to send or explain?')
                    ->required()
                    ->maxLength(TierReview::NOTE_MAX),
            ])
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(TierReview::class)->requestMoreInfo(self::actor(), $this->application(), (string) ($data['request'] ?? ''));

                    return 'Sent back to the organization.';
                });
                $this->reload();
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->authorize(fn (): bool => $this->allows('approve'))
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Approve '.$this->application()->requested_tier->label().'?')
            ->modalDescription('The organization moves to this tier at once and its pending documents are accepted. The org-owners are emailed.')
            ->schema($this->decisionFields())
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(TierReview::class)->approve(self::actor(), $this->application(), (string) ($data['reason'] ?? ''), isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null);

                    return 'Approved. The organization is now at '.$this->application()->requested_tier->label().'.';
                });
                $this->reload();
            });
    }

    public function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->authorize(fn (): bool => $this->allows('reject'))
            ->requiresConfirmation()
            ->modalHeading('Reject this application?')
            ->modalDescription('The organization keeps its current tier and may apply again. The org-owners are emailed.')
            ->schema($this->decisionFields())
            ->action(function (array $data): void {
                self::attempt(function () use ($data): string {
                    app(TierReview::class)->reject(self::actor(), $this->application(), (string) ($data['reason'] ?? ''), isset($data['note_to_owner']) ? (string) $data['note_to_owner'] : null);

                    return 'Rejected.';
                });
                $this->reload();
            });
    }

    /** @return list<Textarea> */
    private function decisionFields(): array
    {
        return [
            Textarea::make('reason')
                ->label('Reason')
                ->helperText('Kept in the audit log with your name; not sent to the customer.')
                ->required()
                ->maxLength(TierReview::NOTE_MAX),
            Textarea::make('note_to_owner')
                ->label('Note to the organization (optional)')
                ->helperText('Shown in their workspace and quoted in the email.')
                ->maxLength(TierReview::NOTE_MAX),
        ];
    }
}
