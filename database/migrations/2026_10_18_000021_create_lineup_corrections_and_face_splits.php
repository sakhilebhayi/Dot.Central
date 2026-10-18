<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-29 (15-lineup-corrections-design.md): corrections of when a machine
 * arrived on or left a face, and the face a split face carries on from.
 * Central-side annotations of the line-up; nothing from Dot.Mines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineup_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lineup_allocation_id')->constrained('lineup_allocations')->cascadeOnDelete();
            $table->unsignedBigInteger('partner_allocation_id')->nullable();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('edge', 16);
            $table->timestamp('old_at');
            $table->timestamp('new_at');
            $table->string('reason', 255);
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'lineup_allocation_id']);
        });

        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->unsignedBigInteger('split_from_face_id')->nullable()->after('closed_by');
            $table->index('split_from_face_id');
        });
    }

    public function down(): void
    {
        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->dropIndex(['split_from_face_id']);
            $table->dropColumn('split_from_face_id');
        });
        Schema::dropIfExists('lineup_corrections');
    }
};
