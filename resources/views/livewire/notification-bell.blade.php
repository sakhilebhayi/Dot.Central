<div class="cb" x-data @click.outside="if ($wire.open) $wire.set('open', false)" @keydown.escape.window="if ($wire.open) $wire.set('open', false)">
    <button type="button" wire:click="toggle" class="dh-icon-btn dh-bell" title="Notifications" aria-haspopup="true" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="cb-panel"
        aria-label="{{ $this->unreadCount > 0 ? 'Notifications, '.$this->unreadCount.' unread' : 'Notifications' }}">
        <span class="material-symbols-rounded" aria-hidden="true">notifications</span>
        @if($this->unreadCount > 0)
            <span class="dh-badge" aria-hidden="true">{{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}</span>
        @endif
    </button>

    @if($open)
    <div class="cb-panel" id="cb-panel" role="region" aria-label="Notifications">
        <div class="cb-panel__head">
            <span class="cb-panel__title">Notifications</span>
            @if($this->unreadCount > 0)
                <button type="button" wire:click="markAllAsRead" class="cb-panel__mark">Mark all read</button>
            @endif
        </div>

        @if($this->notifications->isEmpty())
            <div class="cb-panel__empty">
                <span class="material-symbols-rounded" aria-hidden="true">notifications_off</span>
                <p>No notifications yet.</p>
            </div>
        @else
            <div class="cb-panel__list">
                @foreach($this->notifications as $notification)
                <a href="{{ $notification->data['url'] ?? '#' }}" wire:click="markAsRead('{{ $notification->id }}')" class="cb-item {{ $notification->read_at ? 'is-read' : '' }}">
                    <span class="cb-item__title">
                        @if(!$notification->read_at)<span class="cb-item__dot" aria-hidden="true"></span>@endif
                        {{ $notification->data['title'] ?? 'Notification' }}
                    </span>
                    <span class="cb-item__text">{{ $notification->data['message'] ?? '' }}</span>
                </a>
                @endforeach
            </div>
        @endif

        <div class="cb-panel__foot">
            <a href="{{ route('notifications.index') }}">View all</a>
        </div>
    </div>
    @endif
</div>
