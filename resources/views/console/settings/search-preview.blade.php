{{-- Google result and link card from the draft (PLATFORM_CONSOLE.md § 9.8). Inline styles: the console's CSS is prebuilt. --}}
@php($card = 'border:1px solid rgba(127,127,127,.25);border-radius:.75rem;background:#fff;color:#202124;overflow:hidden')
<div style="position:sticky;top:5rem;display:flex;flex-direction:column;gap:1rem;font-size:.875rem;font-family:Arial,sans-serif">
    <div style="{{ $card }};padding:1rem" data-test="google-preview">
        <p style="margin:0 0 .5rem;font-size:.75rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#5f6368">Google result</p>
        <p style="margin:0;color:#4d5156">{{ $host }}</p>
        <p style="margin:.125rem 0 0;font-size:1.25rem;line-height:1.3;color:#1a0dab">{{ \Illuminate\Support\Str::limit($title, 60) }}</p>
        <p style="margin:.25rem 0 0;color:#4d5156;line-height:1.5">{{ $description !== '' ? \Illuminate\Support\Str::limit($description, 160) : 'No description: search engines pick text from the page.' }}</p>
    </div>
    <div style="{{ $card }}" data-test="share-preview">
        <p style="margin:0;padding:1rem 1rem 0;font-size:.75rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#5f6368">Link card</p>
        @if ($image !== null)
            <img src="{{ $image }}" alt="" style="display:block;margin-top:.75rem;width:100%;aspect-ratio:1200/630;object-fit:cover">
        @else
            <div style="display:flex;align-items:center;justify-content:center;margin-top:.75rem;aspect-ratio:1200/630;padding:0 1.5rem;background:#f1f3f4;color:#5f6368;text-align:center">No share image: links show without a picture.</div>
        @endif
        <div style="padding:.75rem 1rem 1rem;background:#f0f2f5">
            <p style="margin:0;font-size:.75rem;text-transform:uppercase;color:#65676b">{{ $host }}</p>
            <p style="margin:.125rem 0 0;font-weight:700;color:#050505">{{ $title }}</p>
            @if ($description !== '')<p style="margin:.125rem 0 0;color:#65676b">{{ \Illuminate\Support\Str::limit($description, 110) }}</p>@endif
        </div>
    </div>
</div>
