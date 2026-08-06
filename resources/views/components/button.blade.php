<button {{ $attributes->merge(['type' => 'submit', 'class' => 'press inline-flex items-center px-4 py-2 bg-[var(--gold)] border border-transparent rounded-md font-display font-semibold text-xs text-[var(--ink)] uppercase tracking-widest hover:bg-[var(--gold-soft)] focus:bg-[var(--gold-soft)] active:bg-[var(--gold-soft)] focus:outline-none focus:ring-2 focus:ring-[var(--cyan)] focus:ring-offset-2 focus:ring-offset-[var(--panel)] disabled:opacity-50 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
