{{-- The template's whitelist (P05-04): only these are filled in. Inline styles: the console's CSS is prebuilt. --}}
<div data-test="placeholders" style="font-size:.875rem">
    <p style="margin:0 0 .5rem;font-weight:600">Placeholders</p>
    @if ($placeholders === [])
        <p style="margin:0;opacity:.75">This message takes no placeholders.</p>
    @else
        <table style="width:100%;border-collapse:collapse">
            @foreach ($placeholders as $placeholder)
                <tr style="border-top:1px solid rgba(127,127,127,.2)">
                    <td style="padding:.375rem .5rem .375rem 0;white-space:nowrap;vertical-align:top"><code>{{ \App\Mail\Templates\TemplateRenderer::tag($placeholder->name) }}</code></td>
                    <td style="padding:.375rem 0;vertical-align:top">{{ $placeholder->description }} <span style="opacity:.65">e.g. {{ $placeholder->sample }}@if (($sms ?? false) && $placeholder->maxLength() !== null) · up to {{ $placeholder->maxLength() }} characters @endif</span></td>
                </tr>
            @endforeach
        </table>
    @endif
</div>
