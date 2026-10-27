<?php

declare(strict_types=1);

namespace App\Branding;

use App\Documents\DocumentLayout;
use App\Enums\ChecklistItem;
use App\Models\Organization;
use App\Models\User;
use App\Onboarding\OnboardingChecklist;
use App\Theme\Color;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use RuntimeException;

/**
 * Settings → Branding (BRANDING_DOCUMENTS.md § 1): checks and saves an
 * organization's branding. Every rule is enforced here, on the server, so
 * a request that skips the page's checks is refused the same way: plain
 * text only, the colour contrast rules (BrandColours), document prefixes,
 * the layout, the footer line only on plans that may remove it, and the
 * image checks (BrandingFiles). Saving is audited by the organization's
 * activity log (`branding` before and after) and ticks the setup
 * checklist's branding item.
 *
 * Field names are the page's (App\Livewire\Settings\BrandingPage), so a
 * ValidationException lands on the right field.
 */
final readonly class BrandingSettings
{
    public const int NOTES_MAX = 600;

    public const int FOOTER_MAX = 300;

    /** INV, INV-ADR, RCT-ADR-01: capitals and digits, up to three parts. */
    public const string PREFIX_PATTERN = '/^[A-Z0-9]{1,6}(-[A-Z0-9]{1,6}){0,2}$/D';

    public function __construct(
        private BrandingFiles $files,
        private OnboardingChecklist $checklist,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the page's fields
     * @param  array<string, UploadedFile|null>  $uploads  new images by role
     * @param  list<string>  $remove  roles whose image is removed
     *
     * @throws ValidationException
     */
    public function save(User $by, Organization $organization, array $input, array $uploads = [], array $remove = []): Branding
    {
        Gate::forUser($by)->authorize('updateBranding', $organization);

        $data = $this->validate($organization, $input, $uploads);
        $current = Branding::fromOrganization($organization);
        $images = [];

        foreach (Branding::IMAGE_ROLES as $role) {
            $upload = $uploads[$role] ?? null;

            try {
                $images[$role] = $upload instanceof UploadedFile
                    ? $this->files->store($organization, $role, $upload)
                    : (in_array($role, $remove, true) ? null : $current->mediaId($role));
            } catch (RuntimeException $problem) {
                throw ValidationException::withMessages([$role => $problem->getMessage()]);
            }
        }

        $branding = new Branding(
            companyName: $organization->name,
            tradingName: $data['tradingName'],
            primaryColor: strtoupper(Color::normalize((string) $data['primaryColor'])),
            accentColor: $data['accentColor'] !== null ? strtoupper(Color::normalize($data['accentColor'])) : null,
            address: $data['address'],
            rcNumber: $data['rcNumber'],
            phone: $data['phone'],
            email: $data['email'],
            website: $data['website'],
            invoicePrefix: (string) $data['invoicePrefix'],
            receiptPrefix: (string) $data['receiptPrefix'],
            leasePrefix: (string) $data['leasePrefix'],
            invoiceNotes: $data['invoiceNotes'],
            receiptFooter: $data['receiptFooter'],
            layout: DocumentLayout::from((string) $data['layout']),
            emailSenderName: $data['emailSenderName'],
            hidePoweredBy: $data['hidePoweredBy'] === true,
            logoMediaId: $images['logo'],
            signatureMediaId: $images['signature'],
            stampMediaId: $images['stamp'],
        );

        DB::transaction(function () use ($by, $organization, $branding): void {
            $organization->branding = $branding->toArray();
            // The logo's own column (DATABASE_SCHEMA.md, organizations); the
            // json carries the same id for the audit log and document snapshots.
            $organization->logo_media_id = $branding->logoMediaId;
            $organization->save();
            $this->checklist->complete(ChecklistItem::Branding, $by);
        });

        return $branding;
    }

    /**
     * The checked values, trimmed, empty text as null. Prefixes are upper-cased first.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, UploadedFile|null>  $uploads
     * @return array{tradingName: ?string, primaryColor: string, accentColor: ?string, address: ?string, rcNumber: ?string, phone: ?string, email: ?string, website: ?string, invoicePrefix: string, receiptPrefix: string, leasePrefix: string, invoiceNotes: ?string, receiptFooter: ?string, layout: string, emailSenderName: ?string, hidePoweredBy: bool}
     *
     * @throws ValidationException
     */
    public function validate(Organization $organization, array $input, array $uploads = []): array
    {
        $values = self::normalize($input);

        $validator = Validator::make($values + array_filter($uploads), $this->rules($organization), $this->messages(), $this->attributes());
        $validator->after(function (ValidatorInstance $validator) use ($values, $uploads): void {
            if ($validator->errors()->hasAny(['primaryColor', 'accentColor'])) {
                return;
            }

            foreach (BrandColours::problems((string) $values['primaryColor'], $values['accentColor']) as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            foreach (Branding::IMAGE_ROLES as $role) {
                $upload = $uploads[$role] ?? null;
                $problem = $upload instanceof UploadedFile && ! $validator->errors()->has($role) ? $this->files->problem($role, $upload) : null;

                if ($problem !== null) {
                    $validator->errors()->add($role, $problem);
                }
            }
        });

        $validator->validate();

        /** @var array{tradingName: ?string, primaryColor: string, accentColor: ?string, address: ?string, rcNumber: ?string, phone: ?string, email: ?string, website: ?string, invoicePrefix: string, receiptPrefix: string, leasePrefix: string, invoiceNotes: ?string, receiptFooter: ?string, layout: string, emailSenderName: ?string, hidePoweredBy: bool} */
        return $values;
    }

    /**
     * Trimmed text (null when empty), upper-case prefixes, a boolean footer flag.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $text = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }

            $value = trim(str_replace("\r\n", "\n", $value));

            return $value === '' ? null : $value;
        };

        $values = [];

        foreach (['tradingName', 'primaryColor', 'accentColor', 'address', 'rcNumber', 'phone', 'email', 'website', 'invoiceNotes', 'receiptFooter', 'layout', 'emailSenderName'] as $field) {
            $values[$field] = $text($input[$field] ?? null);
        }

        foreach (['invoicePrefix', 'receiptPrefix', 'leasePrefix'] as $field) {
            $values[$field] = ($prefix = $text($input[$field] ?? null)) !== null ? strtoupper($prefix) : null;
        }

        $values['hidePoweredBy'] = ($input['hidePoweredBy'] ?? false) === true;

        return $values;
    }

    /** Whether the organization's plan lets it hide the "Generated with …" line (§ 1, Plan differences). */
    public function mayHidePoweredBy(Organization $organization): bool
    {
        $plans = config('branding.remove_footer_plans', []);

        return is_array($plans) && $organization->signup_plan_key !== null && in_array($organization->signup_plan_key, $plans, true);
    }

    /** @return array<string, list<mixed>> */
    private function rules(Organization $organization): array
    {
        // Plain text everywhere: no markup (documents escape it anyway; this
        // keeps it out of emails and SMS too).
        $plain = 'not_regex:/[<>]/';
        $singleLine = 'not_regex:/[<>\r\n]/';
        $image = ['nullable', 'file', 'max:'.BrandingFiles::MAX_KB];

        return [
            'tradingName' => ['nullable', 'string', 'max:120', $singleLine],
            'primaryColor' => ['required', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D'],
            'accentColor' => ['nullable', 'string', 'regex:/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D'],
            'address' => ['nullable', 'string', 'max:300', $plain],
            'rcNumber' => ['nullable', 'string', 'max:20', 'regex:/^(RC|BN|IT|LP|LLP)?\s?\d{1,9}$/i'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/D'],
            'email' => ['nullable', 'string', 'email', 'max:254'],
            'website' => ['nullable', 'string', 'max:120', 'regex:/^(https?:\/\/)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(\/[^\s<>]*)?$/D'],
            'invoicePrefix' => ['required', 'string', 'max:20', 'regex:'.self::PREFIX_PATTERN, 'different:receiptPrefix', 'different:leasePrefix'],
            'receiptPrefix' => ['required', 'string', 'max:20', 'regex:'.self::PREFIX_PATTERN, 'different:leasePrefix'],
            'leasePrefix' => ['required', 'string', 'max:20', 'regex:'.self::PREFIX_PATTERN],
            'invoiceNotes' => ['nullable', 'string', 'max:'.self::NOTES_MAX, $plain],
            'receiptFooter' => ['nullable', 'string', 'max:'.self::FOOTER_MAX, $plain],
            'layout' => ['required', Rule::enum(DocumentLayout::class)],
            'emailSenderName' => ['nullable', 'string', 'max:60', 'not_regex:/[<>@"\r\n]/'],
            'hidePoweredBy' => ['boolean', function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
                if ($value === true && ! $this->mayHidePoweredBy($organization)) {
                    $fail('Removing the footer line needs the Scale plan.');
                }
            }],
            'logo' => $image,
            'signature' => $image,
            'stamp' => $image,
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'primaryColor.regex' => 'Choose a colour such as #0E6B5C.',
            'accentColor.regex' => 'Choose a colour such as #F2B53A, or leave it empty.',
            '*.not_regex' => 'Use plain text only.',
            'rcNumber.regex' => 'Enter the RC number as on your CAC certificate, such as RC 1234567.',
            'phone.regex' => 'Enter a phone number, such as +234 803 000 0000.',
            'website.regex' => 'Enter a web address, such as adeyemirealty.ng.',
            'invoicePrefix.regex' => 'Use capital letters and digits, in up to three parts joined by hyphens, such as INV-ADR.',
            'receiptPrefix.regex' => 'Use capital letters and digits, in up to three parts joined by hyphens, such as RCT-ADR.',
            'leasePrefix.regex' => 'Use capital letters and digits, in up to three parts joined by hyphens, such as LS-ADR.',
            'invoicePrefix.different' => 'Each document type needs its own prefix.',
            'receiptPrefix.different' => 'Each document type needs its own prefix.',
            'logo.max' => 'The image is larger than 1 MB. Upload a smaller one.',
            'signature.max' => 'The image is larger than 1 MB. Upload a smaller one.',
            'stamp.max' => 'The image is larger than 1 MB. Upload a smaller one.',
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'tradingName' => 'trading name',
            'primaryColor' => 'primary colour',
            'accentColor' => 'accent colour',
            'rcNumber' => 'RC number',
            'invoicePrefix' => 'invoice prefix',
            'receiptPrefix' => 'receipt prefix',
            'leasePrefix' => 'lease prefix',
            'invoiceNotes' => 'invoice notes',
            'receiptFooter' => 'receipt footer',
            'emailSenderName' => 'email sender name',
        ];
    }
}
