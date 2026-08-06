@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'text-sm text-[#f87171]']) }}>{{ $message }}</p>
@enderror
