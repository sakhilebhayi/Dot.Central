<?php

namespace App\Policies;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\User;

/**
 * Alerts come from Dot.Mines and the rule engine. Operators act on them;
 * raising one by hand and escalating are supervisory.
 */
class AlertPolicy
{
    public function create(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('supervise', $controlRoom);
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $alert->cleared_at === null && $user->can('operate', $alert->controlRoom);
    }

    public function assign(User $user, Alert $alert): bool
    {
        return $alert->cleared_at === null && $user->can('operate', $alert->controlRoom);
    }

    public function escalate(User $user, Alert $alert): bool
    {
        return $alert->cleared_at === null && $user->can('supervise', $alert->controlRoom);
    }

    public function resolve(User $user, Alert $alert): bool
    {
        return $alert->cleared_at === null && $user->can('operate', $alert->controlRoom);
    }

    public function update(User $user, Alert $alert): bool
    {
        return $user->can('supervise', $alert->controlRoom);
    }

    public function delete(User $user, Alert $alert): bool
    {
        return $alert->source === 'manual' && $user->can('supervise', $alert->controlRoom);
    }
}
