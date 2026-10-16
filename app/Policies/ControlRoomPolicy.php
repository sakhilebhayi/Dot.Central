<?php

namespace App\Policies;

use App\Models\ControlRoom;
use App\Models\User;
use App\Support\Role;

/**
 * Control rooms are the site boundary. Managers and administrators see every
 * room of their team; operators and supervisors see the rooms they belong to
 * (control_room_user). Only administrators create, configure or delete rooms.
 */
class ControlRoomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null;
    }

    public function view(User $user, ControlRoom $controlRoom): bool
    {
        return $controlRoom->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasAnyRoleOn($user->currentTeam, Role::Administrator);
    }

    public function update(User $user, ControlRoom $controlRoom): bool
    {
        return $this->manage($user, $controlRoom);
    }

    public function delete(User $user, ControlRoom $controlRoom): bool
    {
        return $this->manage($user, $controlRoom);
    }

    /**
     * Configure the room: connection, members, catalogues.
     */
    public function manage(User $user, ControlRoom $controlRoom): bool
    {
        return $user->hasAnyRoleOn($controlRoom->team, Role::Administrator);
    }

    /**
     * Capture operational records in the room (alerts, decisions, sessions).
     */
    public function operate(User $user, ControlRoom $controlRoom): bool
    {
        return $controlRoom->isAccessibleBy($user)
            && $user->hasAnyRoleOn($controlRoom->team, Role::Operator, Role::Supervisor);
    }

    /**
     * Supervise the room: close or delete records, resolve proposals.
     */
    public function supervise(User $user, ControlRoom $controlRoom): bool
    {
        return $controlRoom->isAccessibleBy($user)
            && $user->hasAnyRoleOn($controlRoom->team, Role::Supervisor);
    }
}
