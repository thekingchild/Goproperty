<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use App\Branding\BrandingFiles;
use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Puts a filled-in template into the trusted layout (PLATFORM_CONSOLE.md
 * § 5: "the surrounding layout … is a trusted Blade file in code";
 * resources/views/mail/branded*.blade.php). The template's HTML is the
 * renderer's output, already escaped; the layout adds the brand header
 * (logo, embedded in the email, or the name), the button when the sender
 * gives a link, and the footer.
 */
final readonly class BrandedMail
{
    public function __construct(private BrandingFiles $files) {}

    public function message(RenderedEmail $email, EmailBrand $brand, ?string $url = null): MailMessage
    {
        $mail = (new MailMessage)
            ->from((string) config('mail.from.address'), $brand->senderName)
            ->subject($email->subject)
            ->view(['mail.branded', 'mail.branded-text'], $this->data($email, $brand, $url) + ['logo' => $this->logo($brand)]);

        if ($brand->replyTo !== null) {
            $mail->replyTo($brand->replyTo, $brand->name);
        }

        return $mail;
    }

    /** The email as it will look, for the console's preview (logo as a data URI). */
    public function html(RenderedEmail $email, EmailBrand $brand, ?string $url = null): string
    {
        $logo = $this->logo($brand);

        return view('mail.branded', $this->data($email, $brand, $url) + [
            'logo' => null,
            'logoSrc' => $logo !== null ? 'data:image/png;base64,'.base64_encode($logo) : null,
        ])->render();
    }

    /** @return array<string, mixed> */
    private function data(RenderedEmail $email, EmailBrand $brand, ?string $url): array
    {
        return [
            'email' => $email,
            'brand' => $brand,
            'url' => $url !== null && $email->actionLabel !== null ? $url : null,
            'initials' => self::initials($brand->name),
        ];
    }

    private function logo(EmailBrand $brand): ?string
    {
        if ($brand->organizationId === null || $brand->logoMediaId === null) {
            return null;
        }

        $organization = Organization::query()->find($brand->organizationId);
        $media = $organization !== null ? $this->files->media($organization, 'logo', $brand->logoMediaId) : null;

        return $media !== null ? $this->files->read($media) : null;
    }

    private static function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^\pL\pN]+/u', $name) ?: []));

        return mb_strtoupper(match (count($words)) {
            0 => '',
            1 => mb_substr($words[0], 0, 2),
            default => mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1),
        });
    }
}
