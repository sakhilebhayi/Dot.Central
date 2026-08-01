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

            <div style="margin-bottom:1.5rem;">
                <x-label for="mines_site_ref" value="Dot.Mines Site Reference (optional)" />
                <x-input id="mines_site_ref" name="mines_site_ref" type="text" class="mt-1 block w-full" value="{{ old('mines_site_ref') }}" placeholder="e.g. kolomela" />
            </div>

            <x-button>Create Control Room</x-button>
        </form>
    </div>
</x-app-layout>
