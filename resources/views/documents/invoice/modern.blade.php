{{--
    Invoice, modern layout (BRANDING_DOCUMENTS.md § 1–2, § 5; design:
    docs/design/templates/invoice.html). $invoice: App\Documents\Invoice\InvoiceDocument.
    Table layouts and simple CSS only. One A4 page for up to 8 lines: how to
    pay sits beside the totals (with the signature under them) instead of
    below, and the notes beside the verify box.
--}}
@extends('documents.partials.page', ['docTitle' => 'Invoice '.$invoice->number, 'footerLine' => $invoice->footerLine(), 'void' => $invoice->isVoid()])

@section('content')
    @include('documents.invoice.sections.header')
    <div class="rule"></div>
    @include('documents.invoice.sections.parties')
    <div class="block">@include('documents.invoice.sections.lines')</div>
    <table class="cols block" role="presentation">
      <tr>
        <td class="col" style="width:56%">@include('documents.invoice.sections.payment')</td>
        <td class="gutter" style="width:3%"></td>
        <td class="col" style="width:41%">
          @include('documents.invoice.sections.totals')
          <div class="sign-off">@include('documents.partials.signature', ['brand' => $brand, 'line' => 'For: '.$brand->branding->companyName])</div>
        </td>
      </tr>
    </table>
    <div class="block">@include('documents.invoice.sections.closing')</div>
@endsection
