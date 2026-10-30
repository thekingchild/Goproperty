{{--
    Live preview of the wording on screen (P05-04; PLATFORM_CONSOLE.md § 5),
    with sample values and sample branding. The email is shown in a
    sandboxed frame without scripts or same-origin access. Inline styles:
    the console's CSS is prebuilt.
--}}
<div style="position:sticky;top:5rem;display:flex;flex-direction:column;gap:.5rem" data-test="email-preview">
    <div style="font-size:.875rem;line-height:1.5">
        <div><span style="opacity:.65">From</span> {{ $sender }}</div>
        <div><span style="opacity:.65">Subject</span> <strong data-test="preview-subject">{{ $subject }}</strong></div>
    </div>
    @if ($unknown !== [])
        <div style="padding:.5rem .75rem;border-radius:.5rem;background:rgba(245,158,11,.12);font-size:.8125rem" data-test="unknown-placeholders">
            Not a placeholder of this email, so shown as typed: @foreach ($unknown as $name)<code>{{ \App\Mail\Templates\TemplateRenderer::tag($name) }}</code>@if (! $loop->last), @endif @endforeach
        </div>
    @endif
    @if ($code)
        <div style="padding:.5rem .75rem;border-radius:.5rem;background:rgba(245,158,11,.12);font-size:.8125rem" data-test="code-warning">
            This looks like code (Blade or PHP). Templates never run code: it is sent as plain text.
        </div>
    @endif
    <div style="overflow:hidden;border:1px solid rgba(127,127,127,.25);border-radius:.75rem;background:#fff">
        <iframe sandbox title="Email preview" srcdoc="{{ $html }}" style="display:block;width:100%;height:42rem;border:0"></iframe>
    </div>
</div>
