<div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4 overflow-hidden">
    {{-- Same hero photo as welcome.blade.php (patch-panel/network rack wiring, Yuriy Vertikov),
    reused as-is so the auth pages carry the same photographic identity as the welcome hero. --}}
    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('https://images.unsplash.com/photo-1702478475268-aa8ef54c084e?q=80&w=2400&auto=format&fit=crop');"></div>
    <div class="absolute inset-0" style="background: radial-gradient(ellipse 68% 62% at 50% 40%, rgba(16,20,26,0.9) 0%, rgba(16,20,26,0.68) 45%, rgba(16,20,26,0.35) 74%, rgba(16,20,26,0.12) 100%);"></div>
    <div class="absolute inset-0" style="background: linear-gradient(180deg, rgba(16,20,26,0.6) 0%, transparent 18%, transparent 74%, rgba(16,20,26,0.5) 100%);"></div>

    <div class="relative z-10">
        {{ $logo }}
    </div>

    <div {{ $attributes->merge(['class' => 'relative z-10 w-full sm:max-w-md mt-6 px-6 py-6 sm:py-8 rounded-xl border border-[var(--line)] shadow-2xl overflow-hidden']) }} style="background: var(--panel);">
        {{ $slot }}
    </div>
</div>
