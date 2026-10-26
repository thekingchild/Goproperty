<?php

declare(strict_types=1);

namespace App\Platform\Filament\Pages\Settings;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Settings → Public website (PLATFORM_CONSOLE.md § 9.3): landing and
 * sign-in text, announcement, footer, social links, FAQ and legal pages.
 * Prices are not here: they come from the plans.
 */
final class PublicWebsite extends DraftSettingsPage
{
    protected static ?string $slug = 'settings/website';

    protected static ?string $title = 'Public website';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    /** Rich text for FAQ answers and legal pages: what SafeHtml keeps. */
    private const array TOOLBAR = [['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote', 'horizontalRule'], ['table'], ['undo', 'redo']];

    private const array SOCIAL = ['x' => 'X', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp link'];

    public static function permission(): string
    {
        return 'settings.site';
    }

    public static function settingKeys(): array
    {
        return [
            'site.hero_heading', 'site.hero_subheading', 'site.hero_badge', 'site.hero_lede', 'site.hero_trust',
            'site.auth_headline', 'site.auth_benefits', 'site.auth_trustline',
            'site.announcement', 'site.announcement_link',
            'site.footer_text', 'site.social_links', 'site.faq', 'site.privacy_policy', 'site.terms',
        ];
    }

    protected function fields(): array
    {
        $plain = 'regex:/^[^<>]*$/';

        return [
            Section::make('Landing page hero')
                ->description('Two short lines; the second is in the accent colour.')
                ->columns(2)
                ->schema([
                    TextInput::make('site.hero_heading')->label('Headline, line 1')->required()->maxLength(40)->rule($plain),
                    TextInput::make('site.hero_subheading')->label('Headline, line 2')->required()->maxLength(40)->rule($plain),
                    TextInput::make('site.hero_badge')->label('Badge above the headline')->maxLength(60)->rule($plain)->columnSpanFull(),
                    Textarea::make('site.hero_lede')->label('Intro paragraph')->required()->maxLength(240)->rows(3)->rule($plain)->columnSpanFull(),
                    TextInput::make('site.hero_trust.0')->label('Trust point 1')->required()->maxLength(30)->rule($plain),
                    TextInput::make('site.hero_trust.1')->label('Trust point 2')->required()->maxLength(30)->rule($plain),
                    TextInput::make('site.hero_trust.2')->label('Trust point 3')->required()->maxLength(30)->rule($plain),
                ]),
            Section::make('Sign-in page')
                ->columns(2)
                ->schema([
                    TextInput::make('site.auth_headline')->label('Headline')->required()->maxLength(80)->rule($plain)->columnSpanFull(),
                    TextInput::make('site.auth_benefits.0')->label('Benefit 1')->required()->maxLength(50)->rule($plain),
                    TextInput::make('site.auth_benefits.1')->label('Benefit 2')->required()->maxLength(50)->rule($plain),
                    TextInput::make('site.auth_benefits.2')->label('Benefit 3')->required()->maxLength(50)->rule($plain),
                    TextInput::make('site.auth_trustline')->label('Trust line')->required()->maxLength(80)->rule($plain),
                ]),
            Section::make('Announcement ribbon')
                ->description('Shown above the landing navigation until a visitor dismisses it. Leave empty for none.')
                ->columns(2)
                ->schema([
                    TextInput::make('site.announcement')->label('Text')->maxLength(140)->rule($plain),
                    TextInput::make('site.announcement_link')->label('Link')->url()->maxLength(500)->placeholder('https://…'),
                ]),
            Section::make('Footer')
                ->columns(2)
                ->schema([
                    Textarea::make('site.footer_text')->label('Footer text')->required()->maxLength(200)->rows(2)->rule($plain)->columnSpanFull(),
                    ...array_map(
                        fn (string $network, string $label): TextInput => TextInput::make('site.social_links.'.$network)->label($label)->url()->maxLength(500)->placeholder('https://…'),
                        array_keys(self::SOCIAL),
                        self::SOCIAL,
                    ),
                ]),
            Section::make('Questions and answers')
                ->schema([
                    Repeater::make('site.faq')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('question')->required()->maxLength(140)->rule($plain),
                            RichEditor::make('answer')->required()->toolbarButtons(self::TOOLBAR),
                        ])
                        ->maxItems(30)
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => is_string($state['question'] ?? null) ? $state['question'] : null)
                        ->addActionLabel('Add a question'),
                ]),
            Section::make('Legal pages')
                ->description('Shown at /privacy and /terms. Scripts and styles are removed when saved.')
                ->schema([
                    RichEditor::make('site.privacy_policy')->label('Privacy policy')->required()->toolbarButtons(self::TOOLBAR),
                    RichEditor::make('site.terms')->label('Terms of service')->required()->toolbarButtons(self::TOOLBAR),
                ]),
        ];
    }

    protected function fromFormValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'site.hero_trust', 'site.auth_benefits' => is_array($value) ? array_values(array_map(fn (mixed $item): string => trim((string) $item), $value)) : [],
            'site.social_links' => is_array($value) ? array_filter(array_map(fn (mixed $url): string => trim((string) $url), $value), fn (string $url): bool => $url !== '') : [],
            'site.faq' => is_array($value) ? array_values(array_map(fn (mixed $item): array => [
                'question' => trim((string) (is_array($item) ? ($item['question'] ?? '') : '')),
                'answer' => (string) (is_array($item) ? ($item['answer'] ?? '') : ''),
            ], $value)) : [],
            default => $value,
        };
    }
}
