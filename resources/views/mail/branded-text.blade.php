{{-- Plain-text part of a templated email (P05-04): the template's text, as rendered by TemplateRenderer; text/plain, so printed raw. --}}
{!! $email->text !!}
@if ($url !== null)

{!! $email->actionLabel !!}: {!! $url !!}
@endif

--
{!! $brand->name !!}
@if ($brand->address !== null)
{!! $brand->address !!}
@endif
@if ($brand->showPoweredBy)
Sent with {!! $brand->productName !!} on behalf of {!! $brand->name !!}.
@endif
