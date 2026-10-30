<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\Plans\Pages;

use App\Marketing\PlanCatalogue;
use App\Models\SubscriptionPlan;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\Plans\PlanResource;
use App\Platform\Filament\Resources\Plans\PlanTermsForm;
use App\Platform\Plans\PlanEditor;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;

/** The plans, and "New plan" (its first terms in effect at once: nobody is on it yet). */
final class ListPlans extends ListRecords
{
    use ActsAsStaff;

    protected static string $resource = PlanResource::class;

    public function getSubheading(): string
    {
        return 'What agencies pay GoProperty. A change to a plan that customers are on starts at least 30 days after you save it, and they are told by email and in the app.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newPlan')
                ->label('New plan')
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => self::actor()->can('create', SubscriptionPlan::class))
                ->modalWidth('4xl')
                ->fillForm(fn (): array => PlanTermsForm::fill(app(PlanCatalogue::class)->starter()) + ['custom' => false])
                ->schema([
                    Section::make('Plan')->columns(2)->schema([
                        TextInput::make('key')->required()->maxLength(32)->helperText('Lower-case letters, digits and _; never changes.'),
                        TextInput::make('name')->required()->maxLength(60),
                        Toggle::make('custom')->label('Custom pricing ("from", like Enterprise)'),
                    ]),
                    ...PlanTermsForm::components(),
                    Textarea::make('reason')->required()->maxLength(500)->rows(2)->helperText('Kept in the audit log.'),
                ])
                ->action(function (array $data): void {
                    self::attempt(function () use ($data): string {
                        $plan = app(PlanEditor::class)->create(self::actor(), [
                            'key' => (string) $data['key'],
                            'name' => (string) $data['name'],
                            'custom' => (bool) ($data['custom'] ?? false),
                            'is_active' => false,
                        ], PlanTermsForm::terms($data), (string) $data['reason']);

                        return "Created {$plan->name}. It is not offered to new signups until you switch that on.";
                    });
                }),
        ];
    }
}
