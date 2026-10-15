<button {{ $attributes->merge(['type' => 'button', 'class' => 'dot-btn dot-btn-danger']) }}>
    {{ $slot }}
</button>
