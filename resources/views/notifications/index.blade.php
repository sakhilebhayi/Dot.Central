<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="margin-bottom:2rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Notifications</h1>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Mining-dispatch alerts raised across your control rooms.</p>
        </div>

        @if($notifications->isEmpty())
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;padding:4rem 2rem;text-align:center;">
                <div style="width:56px;height:56px;border-radius:14px;background:rgba(125,211,252,0.12);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                    <span class="material-symbols-rounded" style="font-size:28px;color:#7dd3fc;">notifications_off</span>
                </div>
                <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin:0 0 0.5rem;">No notifications yet</p>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Alerts raised in your control rooms will show up here.</p>
            </div>
        @else
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:1rem;overflow:hidden;">
                @foreach($notifications as $notification)
                    <a href="{{ $notification->data['url'] ?? '#' }}" style="display:block;padding:1rem 1.25rem;border-bottom:1px solid var(--divider);text-decoration:none;{{ $notification->read_at ? 'opacity:0.6;' : '' }}">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:0.25rem;">
                            @if(!$notification->read_at)
                                <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;"></span>
                            @endif
                            <span style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:var(--text-primary);">{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span style="margin-left:auto;font-size:0.7rem;color:var(--text-faint);">{{ $notification->created_at->diffForHumans() }}</span>
                        </div>
                        <p style="font-size:0.78rem;color:var(--text-secondary);margin:0;">{{ $notification->data['message'] ?? '' }}</p>
                    </a>
                @endforeach
            </div>

            <div style="margin-top:1.5rem;">
                {{ $notifications->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
