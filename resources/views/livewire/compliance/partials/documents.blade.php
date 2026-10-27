{{-- The application's documents, by type, with review status and expiry (TierApplicationPage). --}}
@php
    use App\Enums\ComplianceDocumentStatus;
    use App\Livewire\Compliance\TierApplicationPage;

    $documentColor = fn (ComplianceDocumentStatus $status): string => match ($status) {
        ComplianceDocumentStatus::Accepted => 'green',
        ComplianceDocumentStatus::Rejected, ComplianceDocumentStatus::Expired => 'red',
        default => 'zinc',
    };
@endphp
<section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="documents">
    <flux:heading size="lg" level="2">{{ __('Documents') }}</flux:heading>
    <flux:text class="mt-1">{{ __('Kept encrypted in private storage. Only :brand compliance staff can open them, and every view is logged.', ['brand' => $brand]) }}</flux:text>

    <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
        @foreach ([...$required, ...$optional] as $type)
            @php $ofType = $documents->filter(fn ($document) => $document->type === $type); @endphp
            <li class="flex flex-col gap-2 py-3" data-type="{{ $type->value }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-zinc-900 dark:text-white">{{ $type->label() }}</span>
                    @if (in_array($type, $optional, true))
                        <flux:badge size="sm">{{ __('Optional') }}</flux:badge>
                    @elseif ($ofType->contains(fn ($document) => $document->status->counts()))
                        <flux:badge size="sm" color="green">{{ __('Uploaded') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber">{{ __('Missing') }}</flux:badge>
                    @endif
                </div>
                <flux:text class="text-sm">{{ $type->help() }}</flux:text>
                @foreach ($ofType as $document)
                    <div class="flex flex-wrap items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800" data-document="{{ $document->id }}">
                        <flux:icon name="document" variant="micro" class="text-zinc-500" />
                        <span class="min-w-0 truncate">{{ $document->original_name }}</span>
                        @if ($document->holder_name) <span class="text-zinc-500">· {{ $document->holder_name }}</span> @endif
                        <flux:badge size="sm" :color="$documentColor($document->status)">{{ $document->status->label() }}</flux:badge>
                        @if ($note = TierApplicationPage::expiryNote($document))
                            <flux:badge size="sm" color="amber">{{ $note }}</flux:badge>
                        @endif
                        @if ($document->status === ComplianceDocumentStatus::Rejected && $document->review_note)
                            <span class="w-full text-red-700 dark:text-red-300">{{ __('Compliance: :note', ['note' => $document->review_note]) }}</span>
                        @endif
                        @if ($canEdit && $document->status !== ComplianceDocumentStatus::Accepted)
                            <flux:button size="xs" variant="ghost" class="ms-auto" wire:click="removeDocument({{ $document->id }})" wire:confirm="{{ __('Remove this document?') }}">{{ __('Remove') }}</flux:button>
                        @endif
                    </div>
                @endforeach
            </li>
        @endforeach
    </ul>

    @if ($canEdit)
        <form wire:submit="upload" class="mt-4 grid gap-4 rounded-xl border border-dashed border-zinc-300 p-4 sm:grid-cols-2 dark:border-zinc-600" data-test="upload">
            <flux:select wire:model.live="documentType" :label="__('Document')" class="sm:col-span-2">
                <option value="">{{ __('Choose…') }}</option>
                @foreach ([...$required, ...$optional] as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </flux:select>
            <div class="sm:col-span-2">
                <flux:input type="file" wire:model="file" :label="__('File')" accept="application/pdf,image/jpeg,image/png" />
                <flux:text class="mt-1 text-sm">{{ __('PDF, JPEG or PNG, up to :mb MB.', ['mb' => $maxMegabytes]) }}</flux:text>
            </div>
            @if ($selectedType?->needsHolderName())
                <flux:input wire:model="holderName" :label="__('Name on the document')" />
            @endif
            @if ($selectedType?->needsDocumentNumber())
                <flux:input wire:model="documentNumber" :label="__('Document number')" autocomplete="off" />
            @endif
            <flux:input type="date" wire:model="issuedAt" :label="$selectedType?->needsIssueDate() ? __('Date on the document') : __('Issue date (optional)')" />
            <flux:input type="date" wire:model="expiresAt" :label="__('Expiry date, if it has one')" />
            <div class="sm:col-span-2">
                <flux:button type="submit" variant="primary" icon="arrow-up-tray">{{ __('Upload') }}</flux:button>
            </div>
        </form>
    @endif
</section>
