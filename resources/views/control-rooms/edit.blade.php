<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:520px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:#f4f4f5;margin:0 0 1.5rem;">Edit Control Room</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('control-rooms.update', $controlRoom) }}">
            @csrf
            @method('PUT')

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $controlRoom->name) }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="mines_site_ref" value="Dot.Mines Site Reference" />
                <x-input id="mines_site_ref" name="mines_site_ref" type="text" class="mt-1 block w-full" value="{{ old('mines_site_ref', $controlRoom->mines_site_ref) }}" />
            </div>

            <div style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:#a1a1aa;">
                    <input type="checkbox" name="is_active" value="1" {{ $controlRoom->is_active ? 'checked' : '' }} />
                    Active
                </label>
            </div>

            <x-button>Save Changes</x-button>
        </form>

        <form method="POST" action="{{ route('control-rooms.destroy', $controlRoom) }}" style="margin-top:2rem;" onsubmit="return confirm('Delete this control room and all its dispatch decisions, alerts, and operator sessions?');">
            @csrf
            @method('DELETE')
            <x-danger-button type="submit">Delete Control Room</x-danger-button>
        </form>
    </div>
</x-app-layout>
