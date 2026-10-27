{{--
    Lease / tenancy agreement, classic layout (BRANDING_DOCUMENTS.md § 1, § 4, § 5): serif
    headings, a boxed letterhead (which stands in for the rule under it) and
    square bordered panels; the same sections as modern.
    $contract: Modules\Leasing\Documents\LeaseContractDocument. Table layouts
    and simple CSS only. Runs onto as many A4 pages as the agency's clauses
    need, with "Page x of y"; the signatures and the verify box stay together.
--}}
@extends('documents.partials.page', ['docTitle' => 'Tenancy agreement '.$contract->number, 'footerLine' => $contract->footerLine(), 'void' => false])

@section('document-styles')
    @include('leasing::documents.contract.sections.styles')
@endsection

@section('content')
    @include('leasing::documents.contract.sections.header')
    <div class="block">@include('leasing::documents.contract.sections.parties')</div>
    <div class="block">@include('leasing::documents.contract.sections.schedule')</div>
    <div class="block">@include('leasing::documents.contract.sections.clauses')</div>
    <div class="block keep-together">@include('leasing::documents.contract.sections.signatures')</div>
@endsection
