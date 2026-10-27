{{--
    Official receipt, modern layout (BRANDING_DOCUMENTS.md § 1, § 3, § 5;
    design: docs/design/templates/receipt.html). $receipt:
    App\Documents\Receipt\ReceiptDocument. Table layouts and simple CSS only.
--}}
@extends('documents.partials.page', ['docTitle' => 'Receipt '.$receipt->number, 'footerLine' => $receipt->footerLine(), 'void' => $receipt->isVoid()])

@section('content')
    @include('documents.receipt.sections.header')
    <div class="rule"></div>
    @include('documents.receipt.sections.amount')
    <div class="block">@include('documents.receipt.sections.parties')</div>
    <div class="block">@include('documents.receipt.sections.applied')</div>
    <div class="block">@include('documents.receipt.sections.closing')</div>
@endsection
