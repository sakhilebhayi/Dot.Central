@props(['value'])

<label {{ $attributes->merge(['class' => 'jet-label']) }}>
    {{ $value ?? $slot }}
</label>
