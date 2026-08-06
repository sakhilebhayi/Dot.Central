@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'w-full rounded-md border border-[#64748b] bg-[var(--ink)] text-[var(--paper)] placeholder-[var(--mist)] shadow-sm focus:border-[var(--cyan)] focus:ring-[var(--cyan)] focus:ring-1 disabled:opacity-50']) !!}>
