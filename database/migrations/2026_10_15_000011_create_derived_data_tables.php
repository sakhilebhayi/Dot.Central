<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived-operations foundation (docs/dot-central/12-derived-operations-design.md §2):
 * metric history, observed status transitions, mirrored production records,
 * and per-room configuration (settings, shift types, downtime reasons).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('equipment_hour_readings');

        Schema::create('equipment_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('operating_hours', 12, 2)->nullable();
            $table->decimal('idle_hours', 12, 2)->nullable();
            $table->decimal('fuel_level', 8, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->boolean('engine_running')->nullable();
            $table->unsignedBigInteger('load_count')->nullable();
            $table->decimal('payload_cumulative', 16, 2)->nullable();
            $table->decimal('odometer', 12, 2)->nullable();
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->unique(['equipment_id', 'recorded_at']);
        });

        Schema::create('equipment_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('field', 32);
            $table->string('from_value', 32)->nullable();
            $table->string('to_value', 32)->nullable();
            $table->timestamp('observed_at');
            $table->string('source', 16)->default('sync');
            $table->timestamps();

            $table->index(['equipment_id', 'observed_at']);
        });

        Schema::create('production_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mines_record_id');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->unsignedBigInteger('mines_machine_id')->nullable();
            $table->unsignedBigInteger('mine_area_id')->nullable();
            $table->date('record_date');
            $table->string('shift', 16)->nullable();
            $table->decimal('quantity', 14, 2)->nullable();
            $table->string('unit', 16)->nullable();
            $table->string('mines_status', 32)->nullable();
            $table->unsignedBigInteger('sync_version')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'mines_record_id']);
            $table->index(['control_room_id', 'record_date']);
        });

        Schema::create('control_room_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->json('value');
            $table->timestamps();

            $table->unique(['control_room_id', 'key']);
        });

        Schema::create('shift_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['control_room_id', 'code']);
        });

        Schema::create('downtime_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('category', 32);
            $table->boolean('counts_against_availability')->default(true);
            $table->boolean('requires_description')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['control_room_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_reasons');
        Schema::dropIfExists('shift_types');
        Schema::dropIfExists('control_room_settings');
        Schema::dropIfExists('production_records');
        Schema::dropIfExists('equipment_status_events');
        Schema::dropIfExists('equipment_metrics');
    }
};
