<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Agents</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">AI-agent command centre — configure and converse with Claude-powered agents.</p>
            </div>
            <a href="{{ route('agents.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Agent
            </a>
        </div>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            @if($agents->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="width:56px;height:56px;border-radius:14px;background:rgba(225,29,72,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                        <span class="material-symbols-rounded" style="font-size:28px;color:#e11d48;">smart_toy</span>
                    </div>
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No agents yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Create your first AI agent to start chatting.</p>
                    <a href="{{ route('agents.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first agent
                    </a>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Status</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Conversations</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($agents as $agent)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:var(--text-primary);">{{ $agent->name }}</td>
                            <td style="padding:1rem;">
                                @if($agent->is_active)
                                    <span style="color:#22c55e;font-size:0.75rem;font-weight:700;">Active</span>
                                @else
                                    <span style="color:var(--text-muted);font-size:0.75rem;font-weight:700;">Inactive</span>
                                @endif
                            </td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $agent->conversations_count }}</td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <a href="{{ route('agents.show', $agent) }}" style="color:#fda4af;font-size:0.75rem;font-weight:700;text-decoration:none;">Open →</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
