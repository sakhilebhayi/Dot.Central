<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\User;
use App\Notifications\AlertRaisedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_index_page_loads(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Notifications');
    }

    public function test_bell_shows_unread_count_and_can_mark_all_read(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($user->currentTeam)->create();
        $alert = Alert::factory()->for($controlRoom)->create();

        $user->notify(new AlertRaisedNotification($alert));

        $this->actingAs($user);

        Livewire::test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->call('markAllAsRead');

        $this->assertSame(0, $user->fresh()->unreadNotifications()->count());
    }
}
