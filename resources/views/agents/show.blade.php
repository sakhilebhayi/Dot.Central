<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-primary);margin:0;">{{ $agent->name }}</h1>
            <a href="{{ route('agents.edit', $agent) }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">Edit →</a>
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1rem;">{{ $agent->description ?? 'No description.' }}</p>

        @if($agent->skills->isNotEmpty())
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
            @foreach($agent->skills as $skill)
            <span style="display:inline-flex;align-items:center;padding:0.3rem 0.7rem;border-radius:9999px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.2);font-size:0.7rem;font-weight:700;color:#d8b4fe;font-family:'Syne',sans-serif;">{{ $skill->name }}</span>
            @endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('agents.conversations.store', $agent) }}" style="margin-bottom:1.5rem;">
            @csrf
            <button type="submit" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;border:none;cursor:pointer;">
                <span class="material-symbols-rounded" style="font-size:16px;">add_comment</span>
                New Conversation
            </button>
        </form>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0;padding:1.25rem 1.25rem 0.75rem;">Your Conversations</h2>
            @if($conversations->isEmpty())
                <p style="font-size:0.8rem;color:var(--text-muted);padding:0 1.25rem 1.25rem;">No conversations yet — start one above.</p>
            @else
                @foreach($conversations as $conversation)
                <a href="{{ route('agents.chat', [$agent, $conversation]) }}" style="display:block;padding:0.85rem 1.25rem;border-top:1px solid var(--divider);text-decoration:none;">
                    <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">{{ $conversation->title }}</div>
                    <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.15rem;">{{ $conversation->updated_at->diffForHumans() }}</div>
                </a>
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
