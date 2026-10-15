<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shifts are owned by Dot.Central (decision D2): a window per room and
 * shift type, its lifecycle, the handover and the frozen per-unit summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_type_id')->constrained('shift_types')->cascadeOnDelete();
            $table->date('shift_date');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('PLANNED');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['control_room_id', 'shift_type_id', 'shift_date']);
            $table->index(['control_room_id', 'status']);
        });

        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->json('snapshot');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shift_unit_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->decimal('opening_hours', 12, 2)->nullable();
            $table->timestamp('opening_at')->nullable();
            $table->decimal('closing_hours', 12, 2)->nullable();
            $table->timestamp('closing_at')->nullable();
            $table->decimal('run_hours', 8, 2)->nullable();
            $table->decimal('idle_hours', 8, 2)->nullable();
            $table->integer('loads')->nullable();
            $table->decimal('production_quantity', 14, 2)->nullable();
            $table->string('production_unit', 16)->nullable();
            $table->unsignedInteger('stoppage_minutes')->default(0);
            $table->string('state', 16);
            $table->string('freshness', 8);
            $table->json('flags')->nullable();
            $table->timestamps();

            $table->unique(['shift_id', 'equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_unit_summaries');
        Schema::dropIfExists('shift_handovers');
        Schema::dropIfExists('shifts');
    }
};
