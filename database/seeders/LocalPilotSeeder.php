<?php

namespace Database\Seeders;

use App\Models\ControlRoom;
use App\Models\Operator;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Database\Seeder;

/**
 * A local pilot that a fresh developer database can start from without
 * anyone creating users by hand (issue I-27): one team, three users (one
 * per role), one provisioned control room, two operators. No customer,
 * site or person names; the Dot.Mines connection is deliberately left
 * out because its token must be minted by a human in Dot.Mines.
 *
 * Idempotent: running it twice changes nothing. Users get the factory
 * default password; never run this outside a local environment.
 */
class LocalPilotSeeder extends Seeder
{
    public const ADMIN = 'pilot-admin@example.com';

    public const SUPERVISOR = 'pilot-supervisor@example.com';

    public const OPERATOR = 'pilot-operator@example.com';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('LocalPilotSeeder only runs locally.');

            return;
        }

        $admin = User::firstOrCreate(['email' => self::ADMIN], ['email' => self::ADMIN] + User::factory()->raw(['name' => 'Pilot Administrator']));
        $team = $admin->ownedTeams()->where('name', 'Pilot team')->first() ?? $admin->ownedTeams()->create(['name' => 'Pilot team', 'personal_team' => false]);
        $admin->forceFill(['current_team_id' => $team->id])->save();

        $room = ControlRoom::withoutGlobalScopes()->firstOrCreate(['team_id' => $team->id, 'name' => 'Pilot Control Room'], ['slug' => 'pilot-control-room', 'is_active' => true]);
        app(RoomProvisioner::class)->provision($room);

        foreach ([self::SUPERVISOR => ['Pilot Supervisor', 'supervisor'], self::OPERATOR => ['Pilot Operator', 'operator']] as $email => [$name, $role]) {
            $user = User::firstOrCreate(['email' => $email], ['email' => $email] + User::factory()->raw(['name' => $name]));
            if (! $team->users()->whereKey($user->id)->exists()) {
                $team->users()->attach($user, ['role' => $role]);
            }
            $user->forceFill(['current_team_id' => $team->id])->save();
            if (! $room->users()->whereKey($user->id)->exists()) {
                $room->users()->attach($user);
            }
        }

        foreach ([['E-100', 'Operator', 'One'], ['E-101', 'Operator', 'Two']] as [$number, $first, $last]) {
            Operator::withoutGlobalScopes()->firstOrCreate(['control_room_id' => $room->id, 'employee_number' => $number], ['first_name' => $first, 'last_name' => $last, 'is_active' => true]);
        }

        $this->command?->info('Pilot ready: '.self::ADMIN.' (administrator), '.self::SUPERVISOR.' (supervisor), '.self::OPERATOR.' (operator) — factory default password. Mint a Dot.Mines token and attach it under Control Rooms → Pilot Control Room → Configure connection.');
    }
}
