<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\User;

class AlertPolicy
{
    public function create(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('operate', $controlRoom);
    }

    public function update(User $user, Alert $alert): bool
    {
        return $user->can('supervise', $alert->controlRoom);
    }

    public function delete(User $user, Alert $alert): bool
    {
        return $user->can('supervise', $alert->controlRoom);
    }
}
