{{--
    Official receipt (BRANDING_DOCUMENTS.md § 3; design: docs/design/templates/receipt.html).
    $brand: App\Documents\DocumentBranding. $receipt: the receipt's data in
    the shape of App\Documents\SampleDocuments::receipt() (P03-05 passes
    real receipts). Table layouts and simple CSS only (§ 5).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt {{ $receipt['number'] }}</title>
@include('documents.partials.styles', ['brand' => $brand, 'target' => $target ?? \App\Documents\RenderTarget::Web])
</head>
<body class="layout-{{ $brand->layout()->value }}">
@include('documents.partials.footer', ['brand' => $brand, 'target' => $target ?? \App\Documents\RenderTarget::Web, 'pageCount' => $pageCount ?? 1, 'line' => $brand->branding->companyName.' · Receipt '.$receipt['number'], 'verifyUrl' => $receipt['verify_url'], 'at' => 'start'])
<div class="page">
  @include('documents.partials.brand-bar')
  <div class="wrap">

    @include('documents.partials.letterhead', [
        'brand' => $brand,
        'title' => 'OFFICIAL RECEIPT',
        'titleClass' => 'doc-title-receipt',
        'meta' => [
            'Receipt no.' => e($receipt['number']),
            'Date issued' => e($receipt['issued_on']),
            'Tenant ID' => e($receipt['tenant_id']),
        ],
    ])

    <div class="rule"></div>

    <div class="hero">
      <table><tr>
        <td>
          <div class="label">Amount received</div>
          <div class="amount">{{ $receipt['amount'] }}</div>
          <div class="words">{{ $receipt['amount_in_words'] }}</div>
        </td>
        <td style="text-align:right; vertical-align:middle; width:160px"><span class="paid-stamp">PAID</span></td>
      </tr></table>
    </div>

    <table style="margin-top:22px">
      <tr>
        <td style="width:48%">
          <div class="label" style="margin-bottom:4px">Received from</div>
          <table class="kv">
            @foreach ($receipt['received_from'] as $row)
              <tr><td class="k">{{ $row['label'] }}</td><td class="v">{{ $row['value'] }}</td></tr>
            @endforeach
          </table>
        </td>
        <td style="width:4%"></td>
        <td style="width:48%">
          <div class="label" style="margin-bottom:4px">Payment details</div>
          <table class="kv">
            @foreach ($receipt['payment'] as $row)
              <tr><td class="k">{{ $row['label'] }}</td><td class="v">{{ $row['value'] }}</td></tr>
            @endforeach
          </table>
        </td>
      </tr>
    </table>

    <div class="label" style="margin:24px 0 6px 0">Applied to</div>
    <table class="items">
      <tr>
        <th style="width:30%">Invoice</th>
        <th style="width:40%">Description</th>
        <th class="r" style="width:30%">Amount applied</th>
      </tr>
      @foreach ($receipt['applied'] as $row)
        <tr>
          <td>{{ $row['invoice'] }} @if ($row['paid'])<span class="chip">PAID</span>@endif</td>
          <td>{{ $row['description'] }}</td>
          <td class="r">{{ $row['amount'] }}</td>
        </tr>
      @endforeach
    </table>
    <table class="summary" style="margin-top:8px">
      @foreach ($receipt['summary'] as $row)
        <tr><td class="k">{{ $row['label'] }}</td><td class="v">{{ $row['value'] }}</td></tr>
      @endforeach
    </table>

    <table style="margin-top:26px">
      <tr>
        <td style="width:55%">
          <div class="label">Confirmed by</div>
          <div style="font-weight:600; margin-top:4px">{{ $receipt['confirmed_by'] }}</div>
          <div class="small muted">{{ $receipt['confirmed_at'] }}</div>
          @if ($brand->signatureSrc || $brand->stampSrc)
            <table style="width:auto; margin-top:10px"><tr>
              @if ($brand->signatureSrc)<td style="vertical-align:bottom; padding-right:12px"><img class="sig-image" src="{{ $brand->signatureSrc }}" alt="Signature"></td>@endif
              @if ($brand->stampSrc)<td style="vertical-align:bottom"><img class="stamp-image" src="{{ $brand->stampSrc }}" alt="Stamp"></td>@endif
            </tr></table>
          @endif
          <div class="sig-line small muted">Authorised signature / stamp</div>
        </td>
        <td style="width:45%">
          @include('documents.partials.verify-box', ['code' => $receipt['verification_code'], 'label' => 'Verify this receipt'])
        </td>
      </tr>
    </table>

    <div class="small muted" style="margin-top:22px">This receipt was issued electronically and is valid without a signature. A copy was sent to the tenant by SMS/WhatsApp and email.</div>
    @if ($brand->branding->receiptFooter)
      <div class="small muted" style="margin-top:8px">{!! nl2br(e($brand->branding->receiptFooter)) !!}</div>
    @endif
  </div>

  @include('documents.partials.footer', ['brand' => $brand, 'target' => $target ?? \App\Documents\RenderTarget::Web, 'pageCount' => $pageCount ?? 1, 'line' => $brand->branding->companyName.' · Receipt '.$receipt['number'], 'verifyUrl' => $receipt['verify_url'], 'at' => 'end'])
</div>
</body>
</html>
