<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Jetstream roles 'admin' and 'editor' were never checked by the
 * application. They are replaced by the four control-room roles; existing
 * memberships are mapped so nobody loses access.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('team_user')->where('role', 'admin')->update(['role' => 'administrator']);
        DB::table('team_user')->where('role', 'editor')->update(['role' => 'operator']);
        DB::table('team_user')->whereNull('role')->update(['role' => 'operator']);
    }

    public function down(): void
    {
        DB::table('team_user')->where('role', 'administrator')->update(['role' => 'admin']);
        DB::table('team_user')->whereIn('role', ['operator', 'supervisor', 'manager'])->update(['role' => 'editor']);
    }
};
