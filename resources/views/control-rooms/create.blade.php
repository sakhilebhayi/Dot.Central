<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:520px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">New Control Room</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('control-rooms.store') }}" onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.innerText='Creating…';},0);">
            @csrf

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name') }}" required autofocus />
            </div>

            <p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 1.5rem;">The Dot.Mines connection is configured on the room page after it is created.</p>

            <x-button>Create Control Room</x-button>
        </form>
    </div>
</x-app-layout>
