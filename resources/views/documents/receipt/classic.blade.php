{{--
    Official receipt, classic layout (BRANDING_DOCUMENTS.md § 1, § 3, § 5):
    serif headings, a boxed letterhead (which stands in for the rule under
    it), square bordered panels and a ruled table. Same sections as modern.
    $receipt: App\Documents\Receipt\ReceiptDocument. Table layouts and simple CSS only.
--}}
@extends('documents.partials.page', ['docTitle' => 'Receipt '.$receipt->number, 'footerLine' => $receipt->footerLine(), 'void' => $receipt->isVoid()])

@section('content')
    @include('documents.receipt.sections.header')
    <div class="block">@include('documents.receipt.sections.amount')</div>
    <div class="block">@include('documents.receipt.sections.parties')</div>
    <div class="block">@include('documents.receipt.sections.applied')</div>
    <div class="block">@include('documents.receipt.sections.closing')</div>
@endsection
