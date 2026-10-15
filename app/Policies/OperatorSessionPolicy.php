<?php

namespace App\Policies;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\User;

class OperatorSessionPolicy
{
    public function create(User $user, ControlRoom $controlRoom): bool
    {
        return $user->can('operate', $controlRoom);
    }

    public function update(User $user, OperatorSession $operatorSession): bool
    {
        return $user->can('supervise', $operatorSession->controlRoom);
    }

    public function delete(User $user, OperatorSession $operatorSession): bool
    {
        return $user->can('supervise', $operatorSession->controlRoom);
    }
}
