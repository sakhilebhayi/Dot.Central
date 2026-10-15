<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The agents.team_id migration must not fail on a database that already
 * holds agent rows from before team scoping existed: it backfills them
 * with the first team before the column becomes NOT NULL.
 */
class AgentsTeamIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_agents_are_backfilled_with_the_first_team(): void
    {
        // Roll back agent_knowledge tables and the team_id column.
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('agents', 'team_id'));

        $userId = DB::table('users')->insertGetId([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $teamId = DB::table('teams')->insertGetId([
            'user_id' => $userId,
            'name' => 'First Team',
            'personal_team' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('agents')->insert([
            'name' => 'Legacy',
            'slug' => 'legacy',
            'system_prompt' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($teamId, (int) DB::table('agents')->where('slug', 'legacy')->value('team_id'));
    }
}
