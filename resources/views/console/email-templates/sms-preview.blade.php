{{-- The SMS on screen with sample values, and its length against one page (P05-02). Inline styles: the console's CSS is prebuilt. --}}
<div style="position:sticky;top:5rem;display:flex;flex-direction:column;gap:.75rem" data-test="sms-preview">
    <div style="max-width:22rem;padding:.75rem 1rem;border-radius:1rem 1rem 1rem .25rem;background:rgba(127,127,127,.15);font-size:.9375rem;line-height:1.45;white-space:pre-wrap" data-test="sms-text">{{ $text }}</div>
    <div style="font-size:.8125rem;line-height:1.6">
        <div>{{ strlen($text) }} of {{ $page }} characters with these samples.</div>
        <div data-test="sms-longest" style="{{ $longest > $page ? 'color:#b91c1c;font-weight:600' : '' }}">Up to {{ $longest }} with the longest names and amounts{{ $longest > $page ? ': too long to save.' : '.' }}</div>
    </div>
    @if ($outside !== [])
        <div style="padding:.5rem .75rem;border-radius:.5rem;background:rgba(245,158,11,.12);font-size:.8125rem" data-test="sms-outside">Not allowed in an SMS: {{ implode(' ', $outside) }}</div>
    @endif
    @if ($unknown !== [])
        <div style="padding:.5rem .75rem;border-radius:.5rem;background:rgba(245,158,11,.12);font-size:.8125rem" data-test="unknown-placeholders">
            Not a placeholder of this message, so sent as typed: @foreach ($unknown as $name)<code>{{ \App\Mail\Templates\TemplateRenderer::tag($name) }}</code>@if (! $loop->last), @endif @endforeach
        </div>
    @endif
</div>
