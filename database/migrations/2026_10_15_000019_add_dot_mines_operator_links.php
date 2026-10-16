<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dot.Mines now publishes its operator register and operator-machine
 * assignments (sync scopes `operators` / `operator_assignments`, webhook
 * events operator.assigned / operator.unassigned) and the canonical
 * equipment type (I-28, I-30). Mirror what Dot.Mines says is current, and
 * record whether a line-up assignment was pushed back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $table) {
            $table->unsignedBigInteger('mines_operator_id')->nullable()->after('role_class_source');
            $table->unsignedBigInteger('mines_operator_assignment_id')->nullable()->after('mines_operator_id');
            $table->timestamp('mines_operator_assigned_at')->nullable()->after('mines_operator_assignment_id');
            $table->string('mines_operator_shift', 32)->nullable()->after('mines_operator_assigned_at');
        });

        Schema::table('operators', function (Blueprint $table) {
            $table->string('employment_status', 32)->nullable()->after('is_active');
            $table->timestamp('last_synced_at')->nullable()->after('employment_status');
        });

        Schema::table('shift_operator_assignments', function (Blueprint $table) {
            $table->boolean('pushed_to_source')->default(false)->after('assigned_by');
            $table->string('push_note', 255)->nullable()->after('pushed_to_source');
        });
    }

    public function down(): void
    {
        Schema::table('shift_operator_assignments', fn (Blueprint $table) => $table->dropColumn(['pushed_to_source', 'push_note']));
        Schema::table('operators', fn (Blueprint $table) => $table->dropColumn(['employment_status', 'last_synced_at']));
        Schema::table('equipment', fn (Blueprint $table) => $table->dropColumn(['mines_operator_id', 'mines_operator_assignment_id', 'mines_operator_assigned_at', 'mines_operator_shift']));
    }
};
