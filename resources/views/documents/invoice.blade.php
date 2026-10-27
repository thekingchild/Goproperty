{{--
    Invoice (BRANDING_DOCUMENTS.md § 2; design: docs/design/templates/invoice.html).
    $brand: App\Documents\DocumentBranding. $invoice: the bill's data in the
    shape of App\Documents\SampleDocuments::invoice() (P03-05 passes real
    bills). Table layouts and simple CSS only (§ 5), for dompdf and Chrome.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice['number'] }}</title>
@include('documents.partials.styles', ['brand' => $brand])
</head>
<body class="layout-{{ $brand->layout()->value }}">
<div class="page">
  <div class="topbar"></div>
  <div class="wrap">

    @include('documents.partials.letterhead', [
        'brand' => $brand,
        'title' => 'INVOICE',
        'meta' => [
            'Invoice no.' => e($invoice['number']),
            'Issue date' => e($invoice['issued_on']),
            'Due date' => e($invoice['due_on']),
            'Status' => '<span class="badge badge-unpaid">'.e($invoice['status']).'</span>',
        ],
    ])

    <div class="rule"></div>

    <table>
      <tr>
        <td style="width:49%" class="card">
          <div class="label">Billed to</div>
          <div class="party-name">{{ $invoice['billed_to']['name'] }}</div>
          @foreach ($invoice['billed_to']['lines'] as $line)
            <div class="muted small">{{ $line }}</div>
          @endforeach
        </td>
        <td style="width:2%"></td>
        <td style="width:49%" class="card">
          <div class="label">Property</div>
          <div class="party-name">{{ $invoice['property']['name'] }}</div>
          @foreach ($invoice['property']['lines'] as $line)
            <div class="muted small">{{ $line }}</div>
          @endforeach
        </td>
      </tr>
    </table>

    <table class="items" style="margin-top:18px">
      <tr>
        <th style="width:46%">Description</th>
        <th style="width:30%">Period</th>
        <th class="r" style="width:24%">Amount</th>
      </tr>
      @foreach ($invoice['items'] as $index => $item)
        <tr @class(['alt' => $index % 2 === 1])>
          <td><div class="item-desc">{{ $item['description'] }}</div>@if ($item['note'])<div class="muted small">{{ $item['note'] }}</div>@endif</td>
          <td>{{ $item['period'] }}</td>
          <td class="r">{{ $item['amount'] }}</td>
        </tr>
      @endforeach
    </table>

    <table style="margin-top:10px">
      <tr>
        <td style="width:50%"></td>
        <td style="width:50%">
          <table class="totals">
            @foreach ($invoice['totals'] as $total)
              <tr><td class="k">{{ $total['label'] }}</td><td class="v">{{ $total['value'] }}</td></tr>
            @endforeach
          </table>
          <div class="due-box" style="margin-top:8px">
            <table><tr>
              <td><div class="label">Amount due</div><div class="due-amount">{{ $invoice['amount_due'] }}</div></td>
              <td class="due-by">Due by<br><b style="color:#ffffff; font-size:13px">{{ $invoice['due_on_long'] }}</b></td>
            </tr></table>
          </div>
        </td>
      </tr>
    </table>

    <table style="margin-top:18px">
      <tr>
        <td style="width:{{ $invoice['online'] ? '58%' : '100%' }}" class="card">
          <div class="label" style="margin-bottom:6px">Pay by bank transfer</div>
          @if ($invoice['bank'])
            <table class="pay">
              <tr><td class="k">Bank</td><td class="v">{{ $invoice['bank']['bank'] }}</td></tr>
              <tr><td class="k">Account name</td><td class="v">{{ $invoice['bank']['account_name'] }}</td></tr>
              <tr><td class="k">Account number</td><td class="v" style="font-size:14px; letter-spacing:1px">{{ $invoice['bank']['account_number'] }}</td></tr>
              <tr><td class="k" style="padding-top:8px">Narration</td><td class="v" style="padding-top:8px"><span class="ref">{{ $invoice['payment_reference'] }}</span></td></tr>
            </table>
          @endif
          <div class="small muted" style="margin-top:8px">Put the reference <b>{{ $invoice['payment_reference'] }}</b> in your transfer narration so your payment is matched and receipted automatically.</div>
        </td>
        @if ($invoice['online'])
          <td style="width:2%"></td>
          <td style="width:40%" class="online">
            <div class="label" style="margin-bottom:6px">Or pay online</div>
            <div class="small muted" style="margin-bottom:10px">Card, transfer or USSD through {{ $brand->name() }}'s secure Paystack checkout.</div>
            <a class="btn" href="{{ $invoice['online']['link'] }}">{{ $invoice['online']['label'] }}</a>
            <div class="small muted" style="margin-top:8px">{{ preg_replace('#^https?://#', '', $invoice['online']['link']) }}</div>
          </td>
        @endif
      </tr>
    </table>

    <table style="margin-top:18px">
      <tr>
        <td style="width:62%" class="notes">
          @if ($brand->branding->invoiceNotes)
            <div class="label" style="margin-bottom:4px">Notes</div>
            <div>{!! nl2br(e($brand->branding->invoiceNotes)) !!}</div>
          @endif
          @if ($brand->signatureSrc || $brand->stampSrc)
            <table style="width:auto; margin-top:12px"><tr>
              @if ($brand->signatureSrc)<td style="vertical-align:bottom; padding-right:12px"><img class="sig-image" src="{{ $brand->signatureSrc }}" alt="Signature"></td>@endif
              @if ($brand->stampSrc)<td style="vertical-align:bottom"><img class="stamp-image" src="{{ $brand->stampSrc }}" alt="Stamp"></td>@endif
            </tr></table>
          @endif
          <div class="sig-line small muted">For: {{ $brand->branding->companyName }}</div>
        </td>
        <td style="width:38%; text-align:right">
          <table style="width:auto; margin-left:auto"><tr>
            <td style="padding-right:10px; text-align:right; vertical-align:middle" class="small muted">Scan to verify<br>this invoice<br><b style="color:#0f172a">{{ $invoice['verification_code'] }}</b></td>
            <td class="qr">QR code</td>
          </tr></table>
        </td>
      </tr>
    </table>
  </div>

  @include('documents.partials.footer', ['brand' => $brand, 'line' => $brand->branding->companyName.' · Invoice '.$invoice['number'].' · '.$invoice['page'], 'verifyUrl' => $invoice['verify_url']])
</div>
</body>
</html>
