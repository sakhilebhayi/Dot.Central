<x-guest-layout>
    <div class="min-h-screen flex flex-col items-center px-4 pt-6 pb-16 sm:pt-10">
        <x-authentication-card-logo />

        <div class="w-full sm:max-w-2xl mt-6 rounded-xl border border-[var(--line)] shadow-2xl overflow-hidden p-6 sm:p-10" style="background: var(--panel);">
            <div class="prose prose-invert max-w-none prose-a:text-[var(--cyan)]" style="color: var(--paper);">
                {!! $terms !!}
            </div>
        </div>
    </div>
</x-guest-layout>
