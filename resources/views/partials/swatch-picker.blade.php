@php
    /**
     * A radio group of colour swatches.
     *
     * name     — form field name
     * selected — currently chosen hex, or null
     * label    — visible/aria label
     * id       — unique prefix for the radio ids
     */
    $id = $id ?? \Illuminate\Support\Str::random(6);
@endphp

<span class="swatch-field">
    <span class="label">{{ $label }}</span>
    <span class="swatch-picker" role="radiogroup" aria-label="{{ $label }}">
        @foreach (\App\Models\Player::COLOR_SWATCHES as $swatch)
            <input type="radio" id="{{ $id }}-{{ $loop->index }}" name="{{ $name }}"
                   value="{{ $swatch }}" aria-label="Swatch {{ $swatch }}"
                   @checked(($selected ?? null) === $swatch)>
            <label for="{{ $id }}-{{ $loop->index }}" style="--swatch: {{ $swatch }}" title="{{ $swatch }}"></label>
        @endforeach
    </span>
</span>
