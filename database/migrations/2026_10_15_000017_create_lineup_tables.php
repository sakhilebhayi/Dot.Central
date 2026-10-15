<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shift line-up (13-shift-lineup-design.md §5): the one place the
 * control room decides where each machine works, who operates it and why
 * a machine is not working. Every row carries control_room_id for scoping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('copied_from_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lineup_faces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');
            $table->string('kind', 16)->default('LOADING');
            $table->foreignId('mine_area_id')->nullable()->constrained('mine_areas')->nullOnDelete();
            $table->string('material', 64)->nullable();
            $table->string('activity', 64)->nullable();
            $table->string('cut', 32)->nullable();
            $table->json('blocks')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('active_from');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 255)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'status']);
        });

        Schema::create('lineup_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lineup_face_id')->constrained('lineup_faces')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('from_at');
            $table->timestamp('to_at')->nullable();
            $table->string('ended_reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'equipment_id', 'to_at']);
        });

        Schema::create('lineup_standbys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('downtime_reason_id')->constrained('downtime_reasons')->cascadeOnDelete();
            $table->timestamp('from_at');
            $table->timestamp('to_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'equipment_id', 'to_at']);
        });

        Schema::create('shift_operator_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('operators')->cascadeOnDelete();
            $table->timestamp('from_at');
            $table->timestamp('to_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'equipment_id', 'to_at']);
            $table->index(['shift_id', 'operator_id', 'to_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_operator_assignments');
        Schema::dropIfExists('lineup_standbys');
        Schema::dropIfExists('lineup_allocations');
        Schema::dropIfExists('lineup_faces');
        Schema::dropIfExists('shift_lineups');
    }
};
