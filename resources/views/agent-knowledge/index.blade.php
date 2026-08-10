<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Knowledge Documents</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Upload reference material and assign it to agents so their replies can be grounded in it.</p>
            </div>
            <a href="{{ route('agent-knowledge.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Document
            </a>
        </div>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            @if($documents->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No documents yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Upload one to start grounding your agents' replies.</p>
                    <a href="{{ route('agent-knowledge.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        Upload your first document
                    </a>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Title</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Source</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:var(--text-primary);">{{ $doc->title }}</td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $doc->original_filename ?? 'Pasted text' }}</td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <form method="POST" action="{{ route('agent-knowledge.destroy', $doc) }}" style="display:inline;" onsubmit="return confirm('Delete this document? Any agents using it will lose access to it.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="background:none;border:none;color:#f87171;font-size:0.75rem;font-weight:700;cursor:pointer;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
