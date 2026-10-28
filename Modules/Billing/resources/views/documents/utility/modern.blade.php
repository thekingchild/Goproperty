{{--
    Utility bill, modern layout (BRANDING_DOCUMENTS.md § 1, § 4, § 5): the invoice's
    sections (parties, totals, how to pay, notes, verify box) with the utility
    and, for a metered utility, the readings table.
    $utilityBill: Modules\Billing\Documents\UtilityBillDocument; $invoice: its
    App\Documents\Invoice\InvoiceDocument. Table layouts and simple CSS only.
--}}
@extends('documents.partials.page', ['docTitle' => 'Utility bill '.$invoice->number, 'footerLine' => $invoice->footerLine(), 'void' => $invoice->isVoid()])

@section('document-styles')
    @include('billing::documents.utility.sections.styles')
@endsection

@section('content')
    @include('billing::documents.utility.sections.header')
    <div class="rule"></div>
    @include('documents.invoice.sections.parties')
    <div class="block">@include('billing::documents.utility.sections.summary')</div>
    @if ($utilityBill->metered)
        <div class="block">@include('billing::documents.utility.sections.readings')</div>
    @else
        <div class="block">@include('documents.invoice.sections.lines')</div>
    @endif
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
