<?php

declare(strict_types=1);

namespace App\Livewire\Compliance;

use App\Compliance\ApplicationIncomplete;
use App\Compliance\ComplianceFiles;
use App\Compliance\RequiredDocuments;
use App\Compliance\TierApplicationRefused;
use App\Compliance\TierApplications;
use App\Enums\ComplianceDocumentType;
use App\Enums\TransactionTier;
use App\Models\ComplianceDocument;
use App\Models\TierApplication;
use App\Models\User;
use App\Onboarding\TierGuide;
use App\Support\PhoneNumber;
use App\Tenancy\OrganizationContext;
use App\Theme\ThemeContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The org-owner's Transaction Tier application, /o/{organization}/compliance/tier
 * (ONBOARDING_COMPLIANCE.md § 2 and § 5.3): start one for Tier 2 or 3 (the
 * tier asked for at signup is suggested), upload each required document
 * with its number and dates, name the compliance contact for Tier 3,
 * submit, see the review's progress and compliance's requests, and answer
 * them by submitting again. Submitting is refused while anything required
 * is missing. While it is open the organization works at its approved tier.
 *
 * Inside the `organization` middleware (replayed for Livewire updates);
 * refuses a context for another organization. Every action checks
 * TierApplicationPolicy; the services re-check the application's state.
 */
#[Layout('layouts::app')]
#[Title('Transaction Tier')]
final class TierApplicationPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $organizationId;

    public ?int $tier = null;

    public string $documentType = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public string $documentNumber = '';

    public string $holderName = '';

    public string $issuedAt = '';

    public string $expiresAt = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $note = '';

    /** @var list<string> */
    #[Locked]
    public array $problems = [];

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

    public function mount(OrganizationContext $context, TierApplications $applications): void
    {
        $this->organizationId = $context->id();
        $this->authorize('viewAny', TierApplication::class);

        $open = $applications->open();
        $this->tier = ($open->requested_tier ?? $applications->suggestedTier() ?? $this->nextTier())?->value;
        $contact = $open->compliance_contact ?? [];
        $this->contactName = (string) ($contact['name'] ?? '');
        $this->contactEmail = (string) ($contact['email'] ?? '');
        $this->contactPhone = (string) ($contact['phone'] ?? '');
    }

    public function start(TierApplications $applications): void
    {
        $this->authorize('create', TierApplication::class);
        $this->validate(['tier' => ['required', 'integer', Rule::in([TransactionTier::Two->value, TransactionTier::Three->value])]], [], ['tier' => 'tier']);

        $this->attempt(fn () => $applications->start($this->user(), TransactionTier::from((int) $this->tier)), 'Your application has started. Upload the documents below, then submit.');
    }

    public function changeTier(TierApplications $applications): void
    {
        $application = $this->editableApplication($applications);
        $this->validate(['tier' => ['required', 'integer', Rule::in([TransactionTier::Two->value, TransactionTier::Three->value])]], [], ['tier' => 'tier']);

        $this->attempt(fn () => $applications->changeTier($this->user(), $application, TransactionTier::from((int) $this->tier)), 'You are now applying for Tier '.$this->tier.'.');
    }

    public function upload(TierApplications $applications, RequiredDocuments $requirements, ComplianceFiles $files): void
    {
        $application = $this->editableApplication($applications);
        $types = array_map(
            static fn (ComplianceDocumentType $type): string => $type->value,
            [...$requirements->required($application->requested_tier), ...$requirements->optional($application->requested_tier)],
        );
        $type = ComplianceDocumentType::tryFrom($this->documentType);

        $this->validate([
            'documentType' => ['required', Rule::in($types)],
            'file' => ['required', 'file', 'max:'.$files->maxKilobytes(), 'mimes:'.implode(',', ['pdf', 'jpg', 'jpeg', 'png'])],
            'documentNumber' => [Rule::requiredIf($type?->needsDocumentNumber() ?? false), 'nullable', 'string', 'max:64'],
            'holderName' => [Rule::requiredIf($type?->needsHolderName() ?? false), 'nullable', 'string', 'max:120'],
            'issuedAt' => [Rule::requiredIf($type?->needsIssueDate() ?? false), 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'expiresAt' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [
            'file.mimes' => 'Upload a PDF, JPEG or PNG file.',
            'expiresAt.after_or_equal' => 'This document has expired. Upload a valid one.',
        ], [
            'documentType' => 'document',
            'documentNumber' => 'document number',
            'holderName' => 'name on the document',
            'issuedAt' => 'date on the document',
            'expiresAt' => 'expiry date',
        ]);

        $file = $this->file;
        abort_unless($file instanceof TemporaryUploadedFile, 422);

        $saved = $this->attempt(fn () => $applications->upload(
            $this->user(),
            $application,
            ComplianceDocumentType::from($this->documentType),
            $file,
            $this->documentNumber,
            $this->holderName,
            $this->date($this->issuedAt),
            $this->date($this->expiresAt),
        ), ComplianceDocumentType::from($this->documentType)->label().' uploaded.', 'file');

        // The plain temporary upload goes as soon as the encrypted copy exists,
        // or the file was refused: nothing readable is left lying around.
        $file->delete();
        $this->reset('file');

        if ($saved) {
            $this->reset('documentType', 'documentNumber', 'holderName', 'issuedAt', 'expiresAt');
        }
    }

    public function removeDocument(int $documentId, TierApplications $applications): void
    {
        $application = $this->editableApplication($applications);
        $document = $application->documents()->whereKey($documentId)->first();
        abort_if($document === null, 404);

        $this->attempt(fn () => $applications->remove($this->user(), $application, $document), $document->type->label().' removed.');
    }

    public function saveContact(TierApplications $applications): void
    {
        $application = $this->editableApplication($applications);

        $this->validate([
            'contactName' => ['required', 'string', 'max:120'],
            'contactEmail' => ['required', 'string', 'email', 'max:254'],
            'contactPhone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail): void {
                if (PhoneNumber::nigerianMobile(is_string($value) ? $value : '') === null) {
                    $fail('Enter a Nigerian mobile number, such as 0803 123 4567.');
                }
            }],
        ], [], ['contactName' => 'name', 'contactEmail' => 'email', 'contactPhone' => 'phone number']);

        $phone = PhoneNumber::nigerianMobile($this->contactPhone) ?? $this->contactPhone;

        $this->attempt(fn () => $applications->saveComplianceContact($this->user(), $application, $this->contactName, $this->contactEmail, $phone), 'Compliance contact saved.', 'contactName');
    }

    public function submit(TierApplications $applications): void
    {
        $application = $this->editableApplication($applications);
        $this->validate(['note' => ['nullable', 'string', 'max:'.TierApplications::NOTE_MAX]], [], ['note' => 'note']);

        try {
            $applications->submit($this->user(), $application, $this->note);
        } catch (ApplicationIncomplete $incomplete) {
            $this->problems = $incomplete->problems;
            $this->status = null;
            $this->addError('submit', 'Not submitted yet: add what is missing below, then submit again.');

            return;
        } catch (TierApplicationRefused $refused) {
            $this->addError('submit', $refused->getMessage());

            return;
        }

        $this->problems = [];
        $this->note = '';
        $this->status = 'Submitted. Compliance usually replies within '.($application->requested_tier === TransactionTier::Three ? 'about 5' : 'about 2').' business days; we will email you.';
    }

    public function render(OrganizationContext $context, TierApplications $applications, RequiredDocuments $requirements, TierGuide $guide, ComplianceFiles $files): View
    {
        $organization = $context->get();
        $application = $applications->open() ?? $applications->latest();
        $documents = $application?->documents()->latest('id')->get() ?? collect();
        $selected = ComplianceDocumentType::tryFrom($this->documentType);

        return view('livewire.compliance.tier-application-page', [
            'organization' => $organization,
            'application' => $application,
            'documents' => $documents,
            'required' => $application !== null ? $requirements->requiredFor($application) : [],
            'optional' => $application !== null ? $requirements->optionalFor($application) : [],
            'needsContact' => $application !== null && $requirements->needsComplianceContact($application->requested_tier),
            'outstanding' => $application !== null && $application->status->isWithApplicant() ? $requirements->problems($application) : [],
            'tierOptions' => array_values(array_filter($guide->options(), fn ($option): bool => $option->tier->value > $organization->transaction_tier->value)),
            'selectedType' => $selected,
            'maxMegabytes' => intdiv($files->maxKilobytes(), 1024),
            'canEdit' => $application !== null && auth()->user()?->can('update', $application) === true,
            'canStart' => auth()->user()?->can('create', TierApplication::class) === true,
            'brand' => app(ThemeContext::class)->current()->name(),
        ]);
    }

    private function editableApplication(TierApplications $applications): TierApplication
    {
        $application = $applications->open();
        abort_if($application === null, 404);
        $this->authorize('update', $application);

        return $application;
    }

    /** Runs a change; a refusal becomes a field error instead of an exception. */
    private function attempt(callable $change, string $done, string $errorKey = 'application'): bool
    {
        try {
            $change();
        } catch (TierApplicationRefused $refused) {
            $this->addError($errorKey, $refused->getMessage());
            $this->status = null;

            return false;
        }

        $this->resetValidation();
        $this->status = $done;

        return true;
    }

    private function nextTier(): ?TransactionTier
    {
        $current = app(OrganizationContext::class)->get()->transaction_tier;

        return TransactionTier::tryFrom($current->value + 1);
    }

    private function date(string $value): ?CarbonImmutable
    {
        return $value === '' ? null : CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** For the view: a document is about to expire or has. */
    public static function expiryNote(ComplianceDocument $document): ?string
    {
        $days = $document->daysUntilExpiry();

        return match (true) {
            $days === null => null,
            $days < 0 => 'Expired',
            $days === 0 => 'Expires today',
            $days <= (int) config('compliance.expiry_warning_days', 30) => "Expires in {$days} days",
            default => null,
        };
    }
}
