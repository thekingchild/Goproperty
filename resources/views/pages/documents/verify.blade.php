{{--
    The verify page for one document (VerifyDocumentController::show;
    BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7): who issued it, what it
    is, its date and amount, and whether it is valid, voided or superseded.
    Only what App\Documents\Verification\VerifiedDocument carries: no
    tenants, no bank details.
--}}
@php
    $status = $document->status;
    $tone = match ($status) {
        \App\Documents\Verification\VerificationStatus::Valid => 'valid',
        \App\Documents\Verification\VerificationStatus::Voided => 'voided',
        \App\Documents\Verification\VerificationStatus::Superseded => 'superseded',
    };
    $kind = mb_strtolower($document->title);
    $heading = match ($status) {
        \App\Documents\Verification\VerificationStatus::Valid => 'This '.$kind.' is genuine',
        \App\Documents\Verification\VerificationStatus::Voided => 'This '.$kind.' was voided',
        \App\Documents\Verification\VerificationStatus::Superseded => 'This '.$kind.' was replaced',
    };
@endphp
<x-layouts::auth-split :title="$document->title.' '.$document->number" :activity="false">
  <x-slot:head>
    @include('pages.documents.partials.verify-styles')
  </x-slot:head>
  <x-slot:top><a href="{{ route('documents.verify.form') }}">Check another code</a></x-slot:top>

  <header class="auth-heading">
    <span class="auth-heading__icon verify-icon verify-icon--{{ $tone }}" aria-hidden="true">
      @if ($tone === 'valid')
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
      @elseif ($tone === 'voided')
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
      @else
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4"/></svg>
      @endif
    </span>
    <h1>{{ $heading }}</h1>
    <p>Issued by <b>{{ $document->issuer }}</b>.</p>
  </header>

  <dl class="verify-facts" data-status="{{ $status->value }}">
    <div><dt>Status</dt><dd><span class="verify-pill verify-pill--{{ $tone }}">{{ $status->label() }}</span>@if ($document->statusNote) <span class="verify-note">{{ $document->statusNote }}</span>@endif</dd></div>
    @if ($document->isSuperseded() && $document->replacedBy)
      <div><dt>Replaced by</dt><dd>{{ $document->replacedBy }}</dd></div>
    @endif
    <div><dt>Document</dt><dd>{{ $document->title }} {{ $document->number }}</dd></div>
    <div><dt>Issued</dt><dd>{{ \App\Documents\Parts\DocumentDates::day($document->issuedAt) }}</dd></div>
    @if ($document->amount)
      <div><dt>Amount</dt><dd>{{ $document->amount->format() }}</dd></div>
    @endif
    @foreach ($document->details as $label => $value)
      <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
    @endforeach
    <div><dt>Verification code</dt><dd class="verify-code">{{ $code }}</dd></div>
  </dl>

  <ul class="tips">
    @if ($tone === 'valid')
      <li>Check that the paper shows the same number, date and amount. If anything differs, it was changed after it was issued: contact {{ $document->issuer }}.</li>
    @elseif ($tone === 'voided')
      <li>A voided document is no longer proof of anything. Ask {{ $document->issuer }} for the current one.</li>
    @else
      <li>Use the newer document instead, and ask {{ $document->issuer }} if you do not have it.</li>
    @endif
  </ul>
</x-layouts::auth-split>
