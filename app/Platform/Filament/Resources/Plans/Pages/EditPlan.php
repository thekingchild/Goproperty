<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans\Pages;

use App\Marketing\Plan;
use App\Marketing\PlanCatalogue;
use App\Models\SubscriptionPlan;
use App\Plans\PlanChanges;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Plans\PlanResource;
use App\Platform\Filament\Resources\Plans\PlanTermsForm;
use App\Platform\Plans\PlanChangeRefused;
use App\Platform\Plans\PlanEditor;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification as Toast;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * One plan (P06-01a; PLATFORM_CONSOLE.md § 1A): its details, saved at once
 * (they change no bill), today's terms, the scheduled change if any, and
 * "Schedule new terms": prices, units, features and limits as the next
 * version, from a date at least 30 days away when customers are on the
 * plan (App\Platform\Plans\PlanEditor; they are told at once). The versions
 * are listed below with "Cancel" for a scheduled one.
 *
 * @property-read SubscriptionPlan $record
 */
final class EditPlan extends EditRecord
{
    use ActsAsStaff;

    protected static string $resource = PlanResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Terms')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('today')->label('Today')->state(fn (): string => PlanResource::priceLine($this->today())),
                TextEntry::make('customers')->label('Customers on this plan')->state(fn (): string => number_format(app(PlanEditor::class)->customers($this->record->id))),
                TextEntry::make('scheduled')->label('Scheduled change')->columnSpanFull()->placeholder('None')
                    ->state(fn (): ?string => $this->scheduledLine()),
            ]),
            Section::make('Details')->description('Saved at once: they change nothing anyone pays.')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(60),
                TextInput::make('audience')->label('Who it is for')->maxLength(160),
                Textarea::make('highlights')->label('Lines on the pricing page')->rows(4)
                    ->helperText('One a line, up to 8. ":brand" is replaced by the product name.'),
                TextInput::make('trial_days')->label('Trial days')->integer()->minValue(0)->maxValue(90)->required()
                    ->helperText('For trials that start from now.'),
                TextInput::make('sort_order')->label('Order')->integer()->minValue(0)->maxValue(65000),
                Toggle::make('featured')->label('Featured on the pricing page'),
                Toggle::make('is_active')->label('Offered to new signups')
                    ->helperText('Off: customers on it stay; nobody new can choose it.'),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['highlights'] = implode("\n", $this->record->highlights ?? []);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof SubscriptionPlan) {
            throw new Halt;
        }

        try {
            return app(PlanEditor::class)->updateDetails(self::actor(), $record, [
                'name' => (string) ($data['name'] ?? ''),
                'audience' => (string) ($data['audience'] ?? ''),
                'highlights' => preg_split('/\R/', (string) ($data['highlights'] ?? '')) ?: [],
                'featured' => (bool) ($data['featured'] ?? false),
                'trial_days' => (int) ($data['trial_days'] ?? 14),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
        } catch (PlanChangeRefused $refused) {
            Toast::make()->title($refused->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('schedule')
                ->label('Schedule new terms')
                ->icon('heroicon-o-calendar-days')
                ->authorize(fn (): bool => self::actor()->can('update', $this->record))
                ->modalWidth('4xl')
                ->modalDescription(fn (): string => $this->noticeText())
                ->fillForm(fn (): array => ($this->today() !== null ? PlanTermsForm::fill($this->today()) : [])
                    + ['effective_from' => app(PlanEditor::class)->suggestedDate($this->record)->toDateString()])
                ->schema([
                    ...PlanTermsForm::components(),
                    Section::make('When and why')->columns(2)->schema([
                        DatePicker::make('effective_from')->label('From')->required()->native(false)
                            ->minDate(fn (): string => app(PlanEditor::class)->earliestDate($this->record)->toDateString())
                            ->helperText(fn (): string => 'The earliest is '.app(PlanEditor::class)->earliestDate($this->record)->format('j F Y').'. Billing days are the 1st.'),
                        Textarea::make('reason')->required()->maxLength(500)->rows(2)->helperText('Kept in the audit log.'),
                    ]),
                ])
                ->action(function (array $data): void {
                    self::attempt(function () use ($data): string {
                        $version = app(PlanEditor::class)->schedule(
                            self::actor(),
                            $this->record,
                            PlanTermsForm::terms($data),
                            CarbonImmutable::parse((string) $data['effective_from'], 'Africa/Lagos'),
                            (string) $data['reason'],
                        );

                        return "Version {$version->version} starts on ".$version->effective_from->format('j F Y')
                            .($version->notice_given_at !== null ? '. Customers are being told now.' : '.');
                    });
                    $this->refreshFormData([]);
                }),
        ];
    }

    private function today(): ?Plan
    {
        return app(PlanCatalogue::class)->findById($this->record->id);
    }

    private function scheduledLine(): ?string
    {
        $catalogue = app(PlanCatalogue::class);
        $version = $catalogue->scheduled($this->record->id);
        $today = $this->today();

        if ($version === null) {
            return null;
        }

        $changes = $today !== null ? PlanChanges::lines($today, $catalogue->withVersion($version)) : [];

        return 'Version '.$version->version.' from '.$version->effective_from->format('j F Y').': '
            .($changes === [] ? 'no change to the terms' : implode('; ', $changes)).'.';
    }

    private function noticeText(): string
    {
        $customers = app(PlanEditor::class)->customers($this->record->id);

        return $customers > 0
            ? "{$customers} customer".($customers === 1 ? ' is' : 's are').' on this plan. They are emailed and shown a banner as soon as you save, and the new terms start no sooner than '.PlanEditor::NOTICE_DAYS.' days from today, at each customer\'s first billing period from that date.'
            : 'Nobody is on this plan, so the new terms may start today.';
    }
}
