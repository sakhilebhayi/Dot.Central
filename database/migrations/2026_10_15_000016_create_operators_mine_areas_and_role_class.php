<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room configuration the line-up needs and Dot.Mines does not expose:
 * an operator register (columns mirror Dot.Mines' operators so a future
 * sync scope can take over), a role class per machine, and a mirror of
 * Dot.Mines mine areas (13-shift-lineup-design.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mines_operator_id')->nullable();
            $table->string('employee_number', 32);
            $table->string('first_name', 64);
            $table->string('last_name', 64);
            $table->string('preferred_name', 64)->nullable();
            $table->string('tag_id', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'employee_number']);
        });

        Schema::create('mine_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mines_area_id');
            $table->string('name');
            $table->string('status', 32)->nullable();
            $table->unsignedBigInteger('sync_version')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'mines_area_id']);
        });

        Schema::table('equipment', function (Blueprint $table) {
            $table->string('role_class', 16)->nullable()->after('machine_type');
            $table->string('role_class_source', 8)->nullable()->after('role_class');
        });
    }

    public function down(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->dropColumn(['role_class', 'role_class_source']);
        });
        Schema::dropIfExists('mine_areas');
        Schema::dropIfExists('operators');
    }
};
