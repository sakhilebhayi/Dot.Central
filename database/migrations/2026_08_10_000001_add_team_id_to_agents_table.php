<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agents become team-scoped. The column is added nullable, existing rows
 * are backfilled with the first team, and only then is the column made
 * NOT NULL — so the migration also succeeds on a database that already
 * holds agents from before team scoping existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $firstTeamId = DB::table('teams')->min('id');

        if ($firstTeamId !== null) {
            DB::table('agents')->whereNull('team_id')->update(['team_id' => $firstTeamId]);
        }

        if (DB::table('agents')->whereNull('team_id')->doesntExist()) {
            Schema::table('agents', function (Blueprint $table) {
                $table->foreignId('team_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
