<?php

namespace App\Policies;

use App\Models\StaleSessionProposal;
use App\Models\User;

class StaleSessionProposalPolicy
{
    public function resolve(User $user, StaleSessionProposal $staleSessionProposal): bool
    {
        return $user->can('supervise', $staleSessionProposal->controlRoom);
    }
}
