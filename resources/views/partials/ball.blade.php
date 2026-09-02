@php
    /**
     * A pool ball rendered in CSS.
     *
     * number — 1..15, colours and stripes itself the way a real rack does
     * group  — 'solids' | 'stripes', overrides the number's own group
     * color  — any CSS colour, overrides the number's own colour
     * size   — 'sm' | 'lg'
     */
    $number = $number ?? null;
    $group = $group ?? null;
    $color = $color ?? null;
    $size = $size ?? null;

    $palette = ['#f0c14b', '#2d63a8', '#c1483f', '#6b4a8f', '#d97b2e', '#2e8b57', '#7b3a34', '#14100d'];

    if ($number !== null) {
        $color ??= $palette[(max(1, $number) - 1) % 8];
        $group ??= $number > 8 ? 'stripes' : 'solids';
    }

    $group ??= 'solids';
    $color ??= 'var(--chalk-deep)';

    $classes = collect(['ball'])
        ->push($group === 'stripes' ? 'ball--stripe' : null)
        ->push($number === null ? 'ball--blank' : null)
        ->push($size ? 'ball--' . $size : null)
        ->filter()
        ->join(' ');
@endphp
<span class="{{ $classes }}" style="--ball-color: {{ $color }}" aria-hidden="true"><span>{{ $number }}</span></span>
