{{--
    A single-series bar chart for the System analytics page (P08-02): one
    bar per point in the theme's primary colour, a 2px gap between bars, a
    tooltip per bar (its whole slot is the hover target) and no figure
    where a day has none. Server-drawn SVG: no script, so it renders on
    shared hosting and reads the same in light and dark mode. The section's
    CSV holds every value as a table.

    $chart: data-chart name; $points: label => int|null; $format: fn(int): string; $title: what it shows
--}}
@php
    $values = array_filter($points, static fn ($value) => $value !== null);
    $max = $values === [] ? 0 : max(1, ...array_values(array_map('abs', $values)));
    $count = max(1, count($points));
    $slot = 10;
    $height = 100;
    $labels = array_keys($points);
@endphp

<figure style="margin:0" data-chart="{{ $chart }}">
    <div style="display:flex;flex-wrap:wrap;gap:.25rem .75rem;justify-content:space-between;font-size:.75rem;opacity:.7">
        <span>{{ $title }}</span>
        <span>Highest: {{ $values === [] ? '—' : $format(max($values)) }}</span>
    </div>
    <svg viewBox="0 0 {{ $count * $slot }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="{{ $title }}"
        style="display:block;width:100%;height:7rem;margin-top:.25rem;border-bottom:1px solid rgba(127,127,127,.35)">
        @foreach ($points as $label => $value)
            @php
                $x = $loop->index * $slot;
                $barHeight = $value === null || $max === 0 ? 0 : max($value > 0 ? 1 : 0, round(abs($value) / $max * ($height - 4), 2));
            @endphp
            <g>
                <title>{{ $label }}: {{ $value === null ? 'no figures for this day' : $format($value) }}</title>
                <rect x="{{ $x }}" y="0" width="{{ $slot }}" height="{{ $height }}" fill="transparent" />
                @if ($barHeight > 0)
                    <rect x="{{ $x + 1 }}" y="{{ $height - $barHeight }}" width="{{ $slot - 2 }}" height="{{ $barHeight }}" style="fill:var(--primary-500)" />
                @endif
            </g>
        @endforeach
    </svg>
    <div style="display:flex;justify-content:space-between;font-size:.75rem;opacity:.7;margin-top:.25rem">
        <span>{{ $labels[0] ?? '' }}</span>
        <span>{{ $labels[count($labels) - 1] ?? '' }}</span>
    </div>
</figure>
