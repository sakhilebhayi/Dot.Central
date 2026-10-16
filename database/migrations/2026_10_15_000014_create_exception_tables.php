<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational exceptions raised by the rule engine over derived data, their
 * lifecycle events, and the alert workflow columns the rules read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->string('key');
            $table->string('rule_code', 16);
            $table->string('group', 32)->default('Equipment');
            $table->string('severity', 16);
            $table->string('outcome', 32);
            $table->string('title');
            $table->text('message')->nullable();
            $table->text('fix')->nullable();
            $table->json('detail')->nullable();
            $table->string('status', 16)->default('OPEN');
            $table->string('raised_by', 32)->default('SYSTEM');
            $table->timestamp('raised_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->text('explanation')->nullable();
            $table->foreignId('explained_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('explained_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('reopened_count')->default(0);
            $table->timestamps();

            $table->unique(['control_room_id', 'key']);
            $table->index(['control_room_id', 'status', 'severity']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('exception_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operational_exception_id')->constrained('operational_exceptions')->cascadeOnDelete();
            $table->string('action', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('cleared_at');
            $table->foreignId('acknowledged_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('acknowledged_by')->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable()->after('assigned_to');
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('escalated_at');
            $table->foreignId('resolved_by')->nullable()->after('escalation_level')->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['acknowledged_at', 'escalated_at', 'escalation_level', 'resolution_note']);
        });
        Schema::dropIfExists('exception_events');
        Schema::dropIfExists('operational_exceptions');
    }
};
