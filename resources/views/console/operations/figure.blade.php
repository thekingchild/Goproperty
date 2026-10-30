{{-- One dashboard figure (P05-05), linked to the delivery log filtered to it when the viewer may open the log. --}}
@if ($url !== null)
    <a href="{{ $url }}" data-figure="{{ $key }}" style="text-decoration:underline;text-underline-offset:2px">{{ $value }}</a>
@else
    <span data-figure="{{ $key }}">{{ $value }}</span>
@endif
