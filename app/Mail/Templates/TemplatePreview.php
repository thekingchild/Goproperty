<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Branding\Branding;
use App\Models\EmailTemplate;
use App\Theme\ThemeContext;

/**
 * The console's live preview and test email (PLATFORM_CONSOLE.md § 5): a
 * template's wording, saved or not, filled in with the catalog's sample
 * values, in the layout with sample branding — a sample agency for the
 * messages agencies' tenants and staff get, GoProperty's own theme for
 * security and product emails. Never a real organization's details.
 */
final readonly class TemplatePreview
{
    public const string SAMPLE_AGENCY = 'Adeyemi Realty';

    public function __construct(
        private EmailTemplates $templates,
        private BrandedMail $mail,
        private ThemeContext $theme,
    ) {}

    public function render(EmailTemplate $template, TemplateContent $content): RenderedEmail
    {
        return $this->templates->sample($template, $content);
    }

    public function html(EmailTemplate $template, TemplateContent $content): string
    {
        return $this->mail->html($this->render($template, $content), $this->brand($template), $this->sampleUrl());
    }

    public function brand(EmailTemplate $template): EmailBrand
    {
        $theme = $this->theme->current();

        if (in_array($template->category, [TemplateCategory::Security, TemplateCategory::Marketing], true)) {
            return EmailBrand::platform($theme);
        }

        return new EmailBrand(
            name: self::SAMPLE_AGENCY,
            senderName: self::SAMPLE_AGENCY.' via '.$theme->name(),
            primaryColor: Branding::defaultPrimary(),
            productName: $theme->name(),
            replyTo: null,
            address: '12 Admiralty Way, Lekki Phase 1, Lagos',
            phone: '+234 801 234 5678',
            email: 'hello@adeyemirealty.example',
            showPoweredBy: true,
        );
    }

    public function sampleUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/portal';
    }
}
