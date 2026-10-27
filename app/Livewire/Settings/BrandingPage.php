<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Branding\BrandingFiles;
use App\Branding\BrandingSettings;
use App\Documents\BrandingPreview;
use App\Enums\DocumentLayout;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\OrganizationContext;
use App\Theme\Color;
use App\Theme\ThemeContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Settings → Branding, /o/{organization}/settings/branding
 * (BRANDING_DOCUMENTS.md § 1): trading name, logo, colours, letterhead,
 * signature and stamp, document prefixes, invoice notes, receipt footer,
 * layout, email sender name and, on the plans that allow it, the footer
 * line. A live preview of a sample invoice and receipt follows the form as
 * it is typed, before anything is saved: it is rendered from the form's
 * current values with the same templates as real documents, and nothing
 * is written until Save (BrandingSettings checks everything again then,
 * including colour contrast).
 *
 * Org-owners and administrators see it (`settings.view`, kept while
 * suspended: read-only then) and save it (`organization.update`).
 */
#[Layout('layouts::app')]
#[Title('Branding')]
final class BrandingPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    public string $tradingName = '';

    public string $primaryColor = '';

    public string $accentColor = '';

    public string $address = '';

    public string $rcNumber = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    public string $invoicePrefix = '';

    public string $receiptPrefix = '';

    public string $leasePrefix = '';

    public string $invoiceNotes = '';

    public string $receiptFooter = '';

    public string $layout = 'modern';

    public string $emailSenderName = '';

    public bool $hidePoweredBy = false;

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    /** @var TemporaryUploadedFile|null */
    public $signature = null;

    /** @var TemporaryUploadedFile|null */
    public $stamp = null;

    public bool $removeLogo = false;

    public bool $removeSignature = false;

    public bool $removeStamp = false;

    /** Which sample the preview shows. */
    public string $previewing = 'invoice';

    #[Locked]
    public ?string $status = null;

    public function boot(OrganizationContext $context): void
    {
        abort_unless($context->has(), 404);
    }

    public function hydrate(OrganizationContext $context): void
    {
        abort_unless($context->id() === $this->organizationId, 404);
    }

    public function mount(OrganizationContext $context): void
    {
        $organization = $context->get();
        $this->organizationId = $organization->id;
        $this->authorize('viewSettings', $organization);
        $this->fillFrom(Branding::fromOrganization($organization));
    }

    /** A chosen image is checked at once (nothing is stored until Save). */
    public function updated(string $property): void
    {
        if (in_array($property, Branding::IMAGE_ROLES, true)) {
            $this->resetValidation($property);
            $problem = $this->uploadProblem($property);

            if ($problem !== null) {
                $this->addError($property, $problem);
            }
        }
    }

    public function save(OrganizationContext $context, BrandingSettings $settings): void
    {
        $organization = $context->get();
        $this->authorize('updateBranding', $organization);
        $this->status = null;

        $uploads = [];

        foreach (Branding::IMAGE_ROLES as $role) {
            $upload = $this->{$role};
            $uploads[$role] = $upload instanceof UploadedFile ? $upload : null;
        }

        $branding = $settings->save($this->user(), $organization, $this->input(), $uploads, array_values(array_filter([
            $this->removeLogo ? 'logo' : null,
            $this->removeSignature ? 'signature' : null,
            $this->removeStamp ? 'stamp' : null,
        ])));

        // The plain temporary uploads go once the checked copies are stored.
        foreach ($uploads as $upload) {
            if ($upload instanceof TemporaryUploadedFile) {
                $upload->delete();
            }
        }

        $this->reset('logo', 'signature', 'stamp', 'removeLogo', 'removeSignature', 'removeStamp');
        $this->resetValidation();
        $this->fillFrom($branding);
        $this->status = __('Branding saved. New invoices and receipts use it; documents already issued keep theirs.');
    }

    public function render(OrganizationContext $context, BrandingSettings $settings, BrandingPreview $preview, BrandingFiles $files): View
    {
        $organization = $context->get();
        $saved = Branding::fromOrganization($organization);
        $branding = $this->previewBranding($organization, $saved);
        $brand = $preview->forWeb($organization, $branding, $this->previewSources());
        $primary = $branding->primaryColor;

        return view('livewire.settings.branding-page', [
            'organization' => $organization,
            'saved' => $saved,
            'canEdit' => $this->user()->can('updateBranding', $organization),
            'mayHidePoweredBy' => $settings->mayHidePoweredBy($organization),
            'layouts' => DocumentLayout::cases(),
            'invoiceHtml' => $preview->invoice($brand),
            'receiptHtml' => $preview->receipt($brand),
            'colourProblems' => Color::isHex($this->primaryColor) ? BrandColours::problems($this->primaryColor, $this->accentColor !== '' ? $this->accentColor : null) : [],
            'primaryContrast' => round(Color::contrast($primary, '#ffffff'), 1),
            'textShade' => strtoupper(BrandColours::textShade($primary)),
            'imageUrls' => [
                'logo' => $files->url($organization, 'logo', $saved->logoFile),
                'signature' => $files->url($organization, 'signature', $saved->signatureFile),
                'stamp' => $files->url($organization, 'stamp', $saved->stampFile),
            ],
            'productName' => app(ThemeContext::class)->current()->name(),
            'notesMax' => BrandingSettings::NOTES_MAX,
            'footerMax' => BrandingSettings::FOOTER_MAX,
        ]);
    }

    /** @return array<string, mixed> the form, as BrandingSettings takes it */
    private function input(): array
    {
        return [
            'tradingName' => $this->tradingName,
            'primaryColor' => $this->primaryColor,
            'accentColor' => $this->accentColor,
            'address' => $this->address,
            'rcNumber' => $this->rcNumber,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'invoicePrefix' => $this->invoicePrefix,
            'receiptPrefix' => $this->receiptPrefix,
            'leasePrefix' => $this->leasePrefix,
            'invoiceNotes' => $this->invoiceNotes,
            'receiptFooter' => $this->receiptFooter,
            'layout' => $this->layout,
            'emailSenderName' => $this->emailSenderName,
            'hidePoweredBy' => $this->hidePoweredBy,
        ];
    }

    /**
     * The branding as the form holds it now, unsaved. A value that is not
     * valid yet (a half-typed colour) shows as the saved one, so the
     * preview never breaks while typing.
     */
    private function previewBranding(Organization $organization, Branding $saved): Branding
    {
        $values = BrandingSettings::normalize($this->input());
        $colour = static fn (mixed $value, ?string $fallback): ?string => is_string($value) && Color::isHex($value) ? strtoupper(Color::normalize($value)) : $fallback;
        $text = static fn (mixed $value): ?string => is_string($value) ? mb_substr($value, 0, 600) : null;

        return new Branding(
            companyName: $organization->name,
            tradingName: $text($values['tradingName']),
            primaryColor: (string) $colour($values['primaryColor'], $saved->primaryColor),
            accentColor: $values['accentColor'] === null ? null : $colour($values['accentColor'], $saved->accentColor),
            address: $text($values['address']),
            rcNumber: $text($values['rcNumber']),
            phone: $text($values['phone']),
            email: $text($values['email']),
            website: $text($values['website']),
            invoicePrefix: $text($values['invoicePrefix']) ?? $saved->invoicePrefix,
            receiptPrefix: $text($values['receiptPrefix']) ?? $saved->receiptPrefix,
            leasePrefix: $text($values['leasePrefix']) ?? $saved->leasePrefix,
            invoiceNotes: $text($values['invoiceNotes']),
            receiptFooter: $text($values['receiptFooter']),
            layout: DocumentLayout::tryFrom((string) $values['layout']) ?? $saved->layout,
            emailSenderName: $text($values['emailSenderName']),
            hidePoweredBy: $values['hidePoweredBy'] === true,
            logoFile: $this->removeLogo ? null : $saved->logoFile,
            signatureFile: $this->removeSignature ? null : $saved->signatureFile,
            stampFile: $this->removeStamp ? null : $saved->stampFile,
        );
    }

    /** @return array<string, string|null> role => a chosen, checked, unsaved image */
    private function previewSources(): array
    {
        $sources = [];

        foreach (Branding::IMAGE_ROLES as $role) {
            $upload = $this->{$role};

            if ($upload instanceof TemporaryUploadedFile && $this->uploadProblem($role) === null) {
                try {
                    $sources[$role] = $upload->temporaryUrl();
                } catch (Throwable) {
                    // Not previewable on this disk; the saved image shows until Save.
                }
            }
        }

        return $sources;
    }

    private function uploadProblem(string $role): ?string
    {
        $upload = $this->{$role};

        if (! $upload instanceof UploadedFile) {
            return null;
        }

        return app(BrandingFiles::class)->problem($role, $upload);
    }

    private function fillFrom(Branding $branding): void
    {
        $this->tradingName = $branding->tradingName ?? '';
        $this->primaryColor = $branding->primaryColor;
        $this->accentColor = $branding->accentColor ?? '';
        $this->address = $branding->address ?? '';
        $this->rcNumber = $branding->rcNumber ?? '';
        $this->phone = $branding->phone ?? '';
        $this->email = $branding->email ?? '';
        $this->website = $branding->website ?? '';
        $this->invoicePrefix = $branding->invoicePrefix;
        $this->receiptPrefix = $branding->receiptPrefix;
        $this->leasePrefix = $branding->leasePrefix;
        $this->invoiceNotes = $branding->invoiceNotes ?? '';
        $this->receiptFooter = $branding->receiptFooter ?? '';
        $this->layout = $branding->layout->value;
        $this->emailSenderName = $branding->emailSenderName ?? '';
        $this->hidePoweredBy = $branding->hidePoweredBy;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
