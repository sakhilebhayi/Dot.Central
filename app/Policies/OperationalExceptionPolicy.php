<?php

namespace App\Policies;

use App\Models\OperationalException;
use App\Models\Team;
use App\Models\User;
use App\Support\Role;

class OperationalExceptionPolicy
{
    public function view(User $user, OperationalException $exception): bool
    {
        return $user->can('view', $exception->controlRoom);
    }

    public function explain(User $user, OperationalException $exception): bool
    {
        return $exception->status === 'OPEN' && $user->can('operate', $exception->controlRoom);
    }

    public function decide(User $user, OperationalException $exception): bool
    {
        return in_array($exception->status, ['OPEN', 'EXPLAINED'], true) && $exception->outcome !== 'CONFLICT' && $user->can('supervise', $exception->controlRoom);
    }

    public function dismiss(User $user, OperationalException $exception): bool
    {
        return in_array($exception->status, ['OPEN', 'EXPLAINED'], true) && $exception->severity !== 'CRITICAL' && $user->can('supervise', $exception->controlRoom);
    }

    public function resolveConflict(User $user, OperationalException $exception): bool
    {
        $room = $exception->controlRoom;
        $team = $room->team;

        return in_array($exception->status, ['OPEN', 'EXPLAINED'], true) && $exception->outcome === 'CONFLICT'
            && $room->isAccessibleBy($user)
            && $team instanceof Team && $user->hasAnyRoleOn($team, Role::Supervisor, Role::Manager);
    }
}
