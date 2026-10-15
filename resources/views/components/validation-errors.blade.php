@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'jet-errors']) }} role="alert">
        <div class="jet-errors__title">{{ __('Please correct the following') }}</div>

        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
