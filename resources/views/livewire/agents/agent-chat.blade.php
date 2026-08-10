<div style="display:flex;flex-direction:column;height:calc(100vh - 4rem);">
    <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--divider);">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;color:var(--text-primary);margin:0;">{{ $agent->name }}</h1>
        <p style="font-size:0.75rem;color:var(--text-muted);margin:0.15rem 0 0;">{{ $conversation->title }}</p>
    </div>

    <div style="flex:1;overflow-y:auto;padding:1.5rem;display:flex;flex-direction:column;gap:0.85rem;">
        @forelse($this->conversationMessages as $msg)
            <div style="max-width:70%;align-self:{{ $msg->role === 'user' ? 'flex-end' : 'flex-start' }};">
                <div style="background:{{ $msg->role === 'user' ? 'linear-gradient(135deg,#e11d48,#9f1239)' : 'var(--card-bg)' }};border:1px solid {{ $msg->role === 'user' ? 'transparent' : 'var(--card-border)' }};border-radius:0.9rem;padding:0.65rem 0.9rem;color:{{ $msg->role === 'user' ? '#fff' : 'var(--text-primary)' }};font-size:0.85rem;line-height:1.5;white-space:pre-wrap;">{{ $msg->content }}</div>
            </div>
        @empty
            <p style="color:var(--text-muted);font-size:0.8rem;text-align:center;margin-top:2rem;">Say hello to get started.</p>
        @endforelse

        <div wire:loading wire:target="send" style="align-self:flex-start;color:var(--text-muted);font-size:0.78rem;">
            {{ $agent->name }} is typing…
        </div>
    </div>

    @if($error)
    <div style="padding:0.6rem 1.5rem;color:#f87171;font-size:0.78rem;">{{ $error }}</div>
    @endif

    <form wire:submit="send" style="padding:1rem 1.5rem;border-top:1px solid var(--divider);display:flex;gap:0.6rem;">
        <input
            type="text"
            wire:model="message"
            placeholder="Message {{ $agent->name }}…"
            wire:loading.attr="disabled"
            wire:target="send"
            style="flex:1;border-radius:0.6rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.85rem;font-size:0.85rem;"
        />
        @error('message') <span style="color:#f87171;font-size:0.72rem;align-self:center;">{{ $message }}</span> @enderror
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send"
            style="padding:0.6rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;border:none;cursor:pointer;"
        >
            Send
        </button>
    </form>
</div>
