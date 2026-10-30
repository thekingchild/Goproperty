<?php

declare(strict_types=1);

namespace App\Platform\Filament\Resources\EmailTemplates\Pages;

use App\Mail\Templates\EmailTemplates;
use App\Mail\Templates\TemplateCatalog;
use App\Mail\Templates\TemplateContent;
use App\Mail\Templates\TemplatePreview;
use App\Mail\Templates\TemplateRefused;
use App\Mail\Templates\TemplateRenderer;
use App\Mail\Templates\TemplateTestEmail;
use App\Models\EmailTemplate;
use App\Platform\Filament\Concerns\ActsAsStaff;
use App\Platform\Filament\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification as Toast;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Edit one email template (PLATFORM_CONSOLE.md § 5): subject, Markdown
 * body and button label with its placeholders listed, a live preview with
 * sample values beside them (unsaved), "Send test to me", and the versions
 * below (VersionsRelationManager, with rollback). Saving adds a version
 * through EmailTemplates; nothing typed is ever run as code.
 *
 * @property-read EmailTemplate $record
 */
final class EditEmailTemplate extends EditRecord
{
    use ActsAsStaff;

    protected static string $resource = EmailTemplateResource::class;

    /** Test emails per staff member per 10 minutes. */
    public const int TEST_SENDS = 5;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 2])->columnSpanFull()->schema([
                Section::make('Wording')
                    ->description(fn (): string => $this->record->description ?? '')
                    ->schema([
                        TextInput::make('subject')->required()->maxLength(EmailTemplates::SUBJECT_MAX)->live(debounce: 600),
                        Textarea::make('body')->label('Body (Markdown)')->required()->maxLength(EmailTemplates::BODY_MAX)->rows(16)->live(debounce: 600)
                            ->helperText('Markdown: **bold**, *italic*, [link text](address), lists. Placeholders are written {{ name }}; only those listed below are filled in. Anything else, HTML and code included, is shown exactly as typed.'),
                        TextInput::make('action_label')->label('Button')->maxLength(EmailTemplates::LABEL_MAX)->live(debounce: 600)
                            ->helperText('Shown only when the email has a link, e.g. to the bill.'),
                        Toggle::make('active')->label('In use')
                            ->helperText('Off: the email is sent with the wording it shipped with.'),
                        View::make('console.email-templates.placeholders')->viewData(fn (): array => [
                            'placeholders' => app(TemplateCatalog::class)->find($this->record->key)->placeholders ?? [],
                        ]),
                    ]),
                View::make('console.email-templates.preview')->viewData(fn (): array => $this->previewData()),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $content = app(EmailTemplates::class)->content($this->record->key);
        $current = $this->record->currentVersion?->content() ?? $content;

        return [
            'subject' => $current->subject,
            'body' => $current->body,
            'action_label' => $current->actionLabel,
            'active' => $this->record->active,
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof EmailTemplate) {
            throw new Halt;
        }

        $templates = app(EmailTemplates::class);

        try {
            $templates->save($record, self::wording($data), self::actor());
        } catch (TemplateRefused $refused) {
            Toast::make()->title($refused->getMessage())->danger()->send();

            throw new Halt;
        }

        $templates->setActive($record, (bool) ($data['active'] ?? true), self::actor());

        return $record->refresh();
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Saved as version '.($this->record->currentVersion->version ?? 1);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test to me')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->authorize(fn (): bool => self::actor()->can('sendTest', $this->record))
                ->action(function (): void {
                    $staff = self::actor();
                    $key = 'email-template-test:'.$staff->id;

                    if (RateLimiter::tooManyAttempts($key, self::TEST_SENDS)) {
                        Toast::make()->title('Too many test emails. Try again in a few minutes.')->danger()->send();

                        return;
                    }

                    RateLimiter::hit($key, 600);
                    $preview = app(TemplatePreview::class);
                    $content = self::wording($this->data ?? []);

                    Notification::route('mail', $staff->email)->notify(new TemplateTestEmail(
                        $preview->render($this->record, $content),
                        $preview->brand($this->record),
                        $preview->sampleUrl(),
                    ));

                    Toast::make()->title('Test sent to '.$staff->email)->body('It uses the wording on screen, saved or not, with sample values.')->success()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    private function previewData(): array
    {
        $content = self::wording($this->data ?? []);
        $renderer = app(TemplateRenderer::class);
        $allowed = app(TemplateCatalog::class)->find($this->record->key)?->placeholderNames() ?? [];
        $preview = app(TemplatePreview::class);

        return [
            'subject' => $preview->render($this->record, $content)->subject,
            'sender' => $preview->brand($this->record)->senderName,
            'html' => $preview->html($this->record, $content),
            'unknown' => $renderer->unknown($content, $allowed),
            'code' => $renderer->looksLikeCode($content),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function wording(array $data): TemplateContent
    {
        $label = trim((string) ($data['action_label'] ?? ''));

        return new TemplateContent(
            (string) ($data['subject'] ?? ''),
            (string) ($data['body'] ?? ''),
            $label !== '' ? $label : null,
        );
    }
}
