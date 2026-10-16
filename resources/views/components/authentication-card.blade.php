<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4">
    <div>
        {{ $logo }}
    </div>

    <div {{ $attributes->merge(['class' => 'w-full sm:max-w-md mt-6 px-6 py-6 sm:py-8 border border-[var(--rule)]']) }} style="background: var(--panel);">
        {{ $slot }}
    </div>
</div>
