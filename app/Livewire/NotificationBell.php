<?php

namespace App\Livewire;

use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Minimal in-app notification bell for the mining-dispatch domain (e.g.
 * "alert raised" — see App\Notifications\AlertRaisedNotification). Reads
 * from the standard Laravel `database` notification channel via the
 * Notifiable trait already on App\Models\User.
 */
class NotificationBell extends Component
{
    public bool $open = false;

    #[Computed]
    public function notifications(): Collection
    {
        return auth()->user()->notifications()->latest()->limit(10)->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function markAsRead(string $notificationId): void
    {
        auth()->user()->notifications()->where('id', $notificationId)->first()?->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    #[On('notifications-refresh')]
    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
