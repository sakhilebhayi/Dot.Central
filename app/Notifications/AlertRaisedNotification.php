<?php

namespace App\Notifications;

use App\Models\Alert;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) notification sent to team members of a control
 * room when a new alert is raised against it. Dispatched from
 * App\Http\Controllers\AlertController::store().
 */
class AlertRaisedNotification extends Notification
{
    public function __construct(public Alert $alert) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $controlRoom = $this->alert->controlRoom;

        return [
            'type' => 'alert_raised',
            'title' => ucfirst($this->alert->severity).' alert raised',
            'message' => "\"{$this->alert->title}\" was raised in \"{$controlRoom->name}\".",
            'control_room_id' => $controlRoom->id,
            'alert_id' => $this->alert->id,
            'url' => route('control-rooms.show', $controlRoom),
        ];
    }
}
