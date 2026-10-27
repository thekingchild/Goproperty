{{--
    "Visit …/verify and enter the code" (VerifyDocumentController::form;
    BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7): one field; a well-formed
    code goes to its verify page, which is a plain 404 when no document has it.
--}}
<x-layouts::auth-split :title="__('Verify a document')" :activity="false">
  <x-slot:head>
    @include('pages.documents.partials.verify-styles')
  </x-slot:head>

  <header class="auth-heading">
    <span class="auth-heading__icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.4 8.5 8 9 4.6-.5 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg></span>
    <h1>Verify a document</h1>
    <p>Enter the code printed beside the QR code on an invoice or receipt to see whether it is genuine and still valid.</p>
  </header>

  @if ($invalid)
    @include('pages.auth.partials.alert', ['message' => 'That is not a verification code. Codes have 10 letters and digits, like 7KQ2M-X9P4D.'])
  @endif

  <form class="stack" method="get" action="{{ route('documents.verify.form') }}">
    <div class="field">
      <label for="code">Verification code</label>
      <div class="control">
        <input class="input input--mono" id="code" name="code" type="text" value="{{ $typed }}" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="7KQ2M-X9P4D" required autofocus @if ($invalid) aria-invalid="true" @endif>
      </div>
    </div>
    <button class="btn-primary" type="submit"><span class="btn-label">Check</span></button>
  </form>
</x-layouts::auth-split>
