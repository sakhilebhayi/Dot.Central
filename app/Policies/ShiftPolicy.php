<?php

namespace App\Policies;

use App\Models\ControlRoom;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Support\Role;

class ShiftPolicy
{
    public function view(User $user, Shift $shift): bool
    {
        return $user->can('view', $shift->controlRoom);
    }

    public function open(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('operate', $controlRoom);
    }

    /** Build, change and confirm the line-up. */
    public function lineup(User $user, Shift $shift): bool
    {
        return $user->can('operate', $shift->controlRoom);
    }

    public function close(User $user, Shift $shift): bool
    {
        return $user->can('operate', $shift->controlRoom);
    }

    public function acknowledge(User $user, Shift $shift): bool
    {
        return $user->can('operate', $shift->controlRoom);
    }

    public function approve(User $user, Shift $shift): bool
    {
        return $user->can('supervise', $shift->controlRoom);
    }

    public function reopen(User $user, Shift $shift): bool
    {
        $team = $shift->controlRoom->team;

        return $user->can('supervise', $shift->controlRoom)
            || ($team instanceof Team && $user->hasAnyRoleOn($team, Role::Administrator));
    }
}
