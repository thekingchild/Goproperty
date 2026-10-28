{{--
    Owner statement, compact layout (BRANDING_DOCUMENTS.md § 1, § 4, § 5): the
    period, the summary with the net due to the owner and the payout record,
    then rent and utilities collected per unit and the expenses charged,
    building by building. $document: Modules\OwnerStatements\Documents\OwnerStatementDocument;
    $statement: its OwnerStatement; $month: its MonthStatement. Table layouts
    and simple CSS only.
--}}
@extends('documents.partials.page', ['docTitle' => 'Owner statement '.$statement->month->label(), 'footerLine' => $document->footerLine(), 'void' => false])

@section('document-styles')
    @include('ownerstatements::documents.statement.sections.styles')
@endsection

@section('content')
    @include('ownerstatements::documents.statement.sections.header')
    <div class="rule"></div>
    <div class="block">@include('ownerstatements::documents.statement.sections.summary')</div>
    <div class="block">@include('ownerstatements::documents.statement.sections.buildings')</div>
    <div class="block">@include('documents.partials.signature', ['brand' => $brand, 'line' => 'For: '.$brand->branding->companyName])</div>
@endsection
