{{--
    The document kit's sample page (App\Documents\KitSample): every shared
    partial (brand bar, letterhead, footer, verify box) around a table of
    amounts and an amount in words. Used to prove both PDF drivers print
    the kit alike (tests/Feature/Documents/DocumentKitVisualDiffTest.php).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} {{ $number }}</title>
@include('documents.partials.styles', ['brand' => $brand, 'target' => $target])
</head>
<body class="layout-{{ $brand->layout()->value }}">
@include('documents.partials.footer', ['brand' => $brand, 'target' => $target, 'pageCount' => $pageCount, 'line' => $brand->branding->companyName.' · '.$title.' '.$number, 'verifyUrl' => url('/verify'), 'at' => 'start'])
<div class="page">
  @include('documents.partials.brand-bar')
  <div class="wrap">

    @include('documents.partials.letterhead', [
        'brand' => $brand,
        'title' => strtoupper($title),
        'meta' => [
            'Number' => e($number),
            'Date' => e($date),
        ],
    ])

    <div class="rule"></div>

    <table class="items">
      <tr>
        <th style="width:60%">Description</th>
        <th style="width:15%">Period</th>
        <th class="r" style="width:25%">Amount</th>
      </tr>
      @foreach ($rows as $index => $row)
        <tr @class(['alt' => $index % 2 === 1])>
          <td><div class="item-desc">{{ $row['description'] }}</div><div class="muted small">{{ $row['note'] }}</div></td>
          <td>{{ $row['period'] }}</td>
          <td class="r">{{ $row['amount'] }}</td>
        </tr>
      @endforeach
    </table>

    <table style="margin-top:14px">
      <tr>
        <td style="width:50%" class="notes">
          <div class="label" style="margin-bottom:4px">Amount in words</div>
          <div class="words">{{ $words }}</div>
        </td>
        <td style="width:50%">
          <table class="totals">
            <tr><td class="k">Total</td><td class="v">{{ $total }}</td></tr>
          </table>
        </td>
      </tr>
    </table>

    <table style="margin-top:22px">
      <tr>
        <td style="width:52%" class="notes">
          <div class="label" style="margin-bottom:4px">Notes</div>
          <div>{{ $notes }}</div>
          <div class="sig-line small muted">For: {{ $brand->branding->companyName }}</div>
        </td>
        <td style="width:4%"></td>
        <td style="width:44%">
          @include('documents.partials.verify-box', ['code' => $verificationCode, 'label' => 'Verify this document'])
        </td>
      </tr>
    </table>
  </div>

  @include('documents.partials.footer', ['brand' => $brand, 'target' => $target, 'pageCount' => $pageCount, 'line' => $brand->branding->companyName.' · '.$title.' '.$number, 'verifyUrl' => url('/verify'), 'at' => 'end'])
</div>
</body>
</html>
