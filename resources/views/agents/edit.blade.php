<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">Edit Agent</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agents.update', $agent) }}">
            @csrf
            @method('PUT')

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $agent->name) }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="description" value="Description (optional)" />
                <x-input id="description" name="description" type="text" class="mt-1 block w-full" value="{{ old('description', $agent->description) }}" />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="system_prompt" value="System Prompt" />
                <textarea id="system_prompt" name="system_prompt" rows="4" required style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('system_prompt', $agent->system_prompt) }}</textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="model" value="Model" />
                <x-input id="model" name="model" type="text" class="mt-1 block w-full" value="{{ old('model', $agent->model) }}" />
            </div>

            @if($skills->isNotEmpty())
            <div style="margin-bottom:1rem;">
                <x-label value="Skills" />
                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                    @foreach($skills as $skill)
                    <label style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--text-secondary);padding:0.35rem 0.75rem;border-radius:9999px;border:1px solid var(--card-border);">
                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" {{ $agent->skills->contains($skill->id) ? 'checked' : '' }} />
                        {{ $skill->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif

            @if($knowledge->isNotEmpty())
            <div style="margin-bottom:1rem;">
                <x-label value="Knowledge Documents" />
                <div style="margin-top:0.5rem;display:flex;flex-direction:column;gap:0.4rem;">
                    @foreach($knowledge as $doc)
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:var(--text-secondary);">
                        <input type="checkbox" name="knowledge[]" value="{{ $doc->id }}" {{ $agent->knowledge->contains($doc->id) ? 'checked' : '' }} />
                        {{ $doc->title }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif
            <p style="font-size:0.72rem;color:var(--text-muted);margin:-0.5rem 0 1.5rem;">
                <a href="{{ route('agent-knowledge.create') }}" style="color:var(--text-muted);">+ Upload a new document</a>
            </p>

            <div style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="checkbox" name="is_active" value="1" {{ $agent->is_active ? 'checked' : '' }} />
                    Active
                </label>
            </div>

            <x-button>Save Changes</x-button>
        </form>
    </div>
</x-app-layout>
