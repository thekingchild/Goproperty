{{--
    Verify box (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7): the QR code
    opens the verify page for $code (App\Documents\Verification\VerificationCodes),
    which shows whether the document is genuine and its current status.
    $label: "Verify this receipt". $boxed (default true): with a border.
--}}
@php
    $verifyUrl = \App\Documents\Verification\VerificationCodes::url($code);
    $verifyHost = preg_replace('#^https?://#', '', url('/verify'));
@endphp
<table role="presentation" @class(['verify' => $boxed ?? true])><tr>
  <td style="vertical-align:middle">
    <div class="label">{{ $label }}</div>
    <div class="small muted" style="margin:4px 0">Scan the code or visit {{ $verifyHost }} and enter:</div>
    <div class="code">{{ \App\Documents\Verification\VerificationCodes::format($code) }}</div>
  </td>
  <td class="verify-qr"><img class="qr" src="{{ \App\Documents\QrCode::dataUri($verifyUrl) }}" alt="QR code for {{ $verifyUrl }}"></td>
</tr></table>
