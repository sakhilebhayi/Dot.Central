<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Role;

class AuditEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentTeam !== null
            && $user->hasAnyRoleOn($user->currentTeam, Role::Supervisor, Role::Manager, Role::Administrator);
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }
}
