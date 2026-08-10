<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">New Agent</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agents.store') }}">
            @csrf

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name') }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="description" value="Description (optional)" />
                <x-input id="description" name="description" type="text" class="mt-1 block w-full" value="{{ old('description') }}" />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="system_prompt" value="System Prompt" />
                <textarea id="system_prompt" name="system_prompt" rows="4" required style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('system_prompt') }}</textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="model" value="Model" />
                <x-input id="model" name="model" type="text" class="mt-1 block w-full" value="{{ old('model', 'claude-sonnet-4-6') }}" />
            </div>

            @if($skills->isNotEmpty())
            <div style="margin-bottom:1.5rem;">
                <x-label value="Skills" />
                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                    @foreach($skills as $skill)
                    <label style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--text-secondary);padding:0.35rem 0.75rem;border-radius:9999px;border:1px solid var(--card-border);">
                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" {{ in_array($skill->id, old('skills', [])) ? 'checked' : '' }} />
                        {{ $skill->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif

            <x-button>Create Agent</x-button>
        </form>
    </div>
</x-app-layout>
