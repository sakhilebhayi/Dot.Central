<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">New Document</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agent-knowledge.store') }}" enctype="multipart/form-data" x-data="{ mode: 'text' }">
            @csrf

            <div style="margin-bottom:1rem;">
                <x-label for="title" value="Title" />
                <x-input id="title" name="title" type="text" class="mt-1 block w-full" value="{{ old('title') }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;display:flex;gap:1rem;">
                <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="radio" name="input_mode" value="text" x-model="mode" checked />
                    Paste text
                </label>
                <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="radio" name="input_mode" value="file" x-model="mode" />
                    Upload a file (.txt, .md, .pdf)
                </label>
            </div>

            <div x-show="mode === 'text'" style="margin-bottom:1.5rem;">
                <x-label for="content" value="Content" />
                <textarea id="content" name="content" rows="10" style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('content') }}</textarea>
            </div>

            <div x-show="mode === 'file'" style="margin-bottom:1.5rem;">
                <x-label for="file" value="File" />
                <input id="file" name="file" type="file" accept=".txt,.md,.pdf" style="margin-top:0.25rem;display:block;width:100%;color:var(--text-secondary);font-size:0.85rem;" />
                <p style="font-size:0.72rem;color:var(--text-muted);margin:0.35rem 0 0;">Max 5MB. Scanned/image-only PDFs with no real text layer will be rejected.</p>
            </div>

            <x-button>Upload Document</x-button>
        </form>
    </div>
</x-app-layout>
