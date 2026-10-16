<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A face is placed at a location picked from Dot.Mines geofences, else
 * Dot.Mines mine areas, else generic mining names (owner instruction
 * 2026-10-15). Geofences are mirrored from GET /api/v1/geofences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mines_geofence_id');
            $table->string('name');
            $table->string('type', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->unsignedBigInteger('mine_area_id')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'mines_geofence_id']);
        });

        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->string('location_source', 16)->nullable()->after('mine_area_id');
            $table->unsignedBigInteger('location_ref')->nullable()->after('location_source');
            $table->string('location_name', 120)->nullable()->after('location_ref');
        });
    }

    public function down(): void
    {
        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->dropColumn(['location_source', 'location_ref', 'location_name']);
        });
        Schema::dropIfExists('geofences');
    }
};
