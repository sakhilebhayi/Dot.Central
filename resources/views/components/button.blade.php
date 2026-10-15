<button {{ $attributes->merge(['type' => 'submit', 'class' => 'dot-btn dot-btn-primary']) }}>
    {{ $slot }}
</button>
