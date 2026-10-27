<?php

namespace App\Providers;

use App\Models\Team;
use App\Models\User;
use App\Services\Rules\ExceptionShiftBlockers;
use App\Services\Shifts\ShiftBlockers;
use App\Support\Role;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShiftBlockers::class, ExceptionShiftBlockers::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // Integration health is readable by supervisors and above; only
        // administrators act on it (docs/dot-central/07-security-and-permissions.md).
        Gate::define('view-health', function (User $user): bool {
            $team = $user->currentTeam;

            return $team instanceof Team && $user->hasAnyRoleOn($team, Role::Supervisor, Role::Manager, Role::Administrator);
        });

        Gate::define('export-reports', function (User $user): bool {
            $team = $user->currentTeam;

            return $team instanceof Team && $user->hasAnyRoleOn($team, Role::Supervisor, Role::Manager, Role::Administrator);
        });

        Gate::define('act-on-health', function (User $user): bool {
            $team = $user->currentTeam;

            return $team instanceof Team && $user->hasAnyRoleOn($team, Role::Administrator);
        });
    }
}
