<button {{ $attributes->merge(['type' => 'button', 'class' => 'dot-btn dot-btn-ghost']) }}>
    {{ $slot }}
</button>
