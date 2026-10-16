<?php

namespace App\Policies;

use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\User;

class DispatchDecisionPolicy
{
    public function create(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('operate', $controlRoom);
    }

    public function delete(User $user, DispatchDecision $dispatchDecision): bool
    {
        return $user->can('supervise', $dispatchDecision->controlRoom);
    }
}
