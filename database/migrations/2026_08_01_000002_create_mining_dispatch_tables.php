<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mining-dispatch domain (MVP scaffold).
 *
 * Adds Dot.Central's "operational intelligence" data model alongside the
 * existing AI-agent command-center tables (agents, conversations, messages).
 * See Dot.Brain's platforms/dot-central.md §2 for the entity definitions
 * this mirrors, and this repo's wiki.md for scope/gap notes.
 *
 * Tenancy: control_rooms is the tenant root, scoped by team_id (Jetstream
 * teams — the same tenancy mechanism already used elsewhere in this repo).
 * All child tables scope through control_room_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            // External reference to the Dot.Mines site this control room maps to.
            // Intentionally a plain string, not a foreign key — Dot.Mines is a
            // separate application/database; no cross-platform API integration
            // is in scope for this MVP pass.
            $table->string('mines_site_ref')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('dispatch_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            // Dispatch workflow type. Kept as a simple enum column rather than
            // a separate lookup table — per Dot.Brain's §2, it's a fixed set
            // of four values per site (assign, reroute, hold, stagger), not
            // an entity with its own lifecycle.
            $table->enum('workflow_type', ['assign', 'reroute', 'hold', 'stagger']);
            $table->unsignedInteger('sequence');
            $table->timestamp('decided_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('summary')->nullable();
            $table->timestamps();

            // Unit-of-record identity per Dot.Brain §2: room + timestamp + sequence.
            $table->unique(['control_room_id', 'sequence']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->enum('severity', ['info', 'warning', 'critical'])->default('warning');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            // The operator staffing the control room during this session.
            // No individual performance metrics are attached here or anywhere
            // else in this schema, per Dot.Brain §8's privacy note.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shift_label');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_sessions');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('dispatch_decisions');
        Schema::dropIfExists('control_rooms');
    }
};
