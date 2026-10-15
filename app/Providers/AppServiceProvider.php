<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Integration health is readable by supervisors and above; only
        // administrators act on it (docs/dot-central/07-security-and-permissions.md).
        Gate::define('view-health', function (User $user): bool {
            $team = $user->currentTeam;

            return $team instanceof Team && $user->hasAnyRoleOn($team, Role::Supervisor, Role::Manager, Role::Administrator);
        });

        Gate::define('act-on-health', function (User $user): bool {
            $team = $user->currentTeam;

            return $team instanceof Team && $user->hasAnyRoleOn($team, Role::Administrator);
        });
    }
}
