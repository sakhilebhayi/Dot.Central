<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror of Dot.Mines machines per control room, with provenance: three
 * clocks (provider recorded_at, Dot.Mines ingest, our sync) and the raw
 * resource. Dot.Mines owns the facts; this is a cache for operational
 * screens, never edited by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mines_machine_id');
            $table->string('name');
            $table->string('machine_type')->nullable();
            $table->string('model')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('mines_status', 32)->default('unknown');
            $table->string('allocation_state', 64)->nullable();
            $table->unsignedBigInteger('mine_area_id')->nullable();
            $table->string('mine_area_name')->nullable();
            $table->decimal('capacity', 10, 2)->nullable();
            $table->decimal('fuel_capacity', 10, 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('location_recorded_at')->nullable();
            $table->decimal('fuel_level', 8, 2)->nullable();
            $table->string('fuel_level_unit', 16)->nullable();
            $table->decimal('engine_hours', 12, 2)->nullable();
            $table->timestamp('engine_hours_recorded_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->comment('Dot.Mines ingest clock of the newest metric');
            $table->timestamp('last_heard_at')->nullable()->comment('newest provider clock across location and metrics');
            $table->unsignedBigInteger('sync_version')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('first_synced_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'mines_machine_id']);
            $table->index(['control_room_id', 'mines_status']);
            $table->index(['control_room_id', 'last_heard_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
