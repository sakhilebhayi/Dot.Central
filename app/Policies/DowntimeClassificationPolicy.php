<?php

namespace App\Policies;

use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\User;

class DowntimeClassificationPolicy
{
    public function create(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('operate', $controlRoom);
    }

    public function delete(User $user, DowntimeClassification $classification): bool
    {
        return $user->can('supervise', $classification->controlRoom);
    }
}
