{{--
    Invoice, compact layout (BRANDING_DOCUMENTS.md § 1–2, § 5): smaller type
    and tighter rows for long bills, a thinner brand bar; the same sections
    and arrangement as modern. $invoice: App\Documents\Invoice\InvoiceDocument.
    Table layouts and simple CSS only.
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
