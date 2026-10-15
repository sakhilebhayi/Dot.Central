<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mines_site_ref had no referent: Dot.Mines has no site entity (its tenant
 * is the team). The mapping now lives on mines_connections.mines_team_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_rooms', function (Blueprint $table) {
            $table->dropColumn('mines_site_ref');
        });
    }

    public function down(): void
    {
        Schema::table('control_rooms', function (Blueprint $table) {
            $table->string('mines_site_ref')->nullable();
        });
    }
};
