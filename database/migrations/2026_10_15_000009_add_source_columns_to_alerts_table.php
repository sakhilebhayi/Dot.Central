<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts now have an origin: manual (typed in), dot_mines (mirrored, deduped
 * on external_id) or system (Dot.Central's own health findings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('source', 24)->default('manual')->after('control_room_id');
            $table->string('external_id', 64)->nullable()->after('source');
            $table->string('priority', 16)->nullable()->after('severity');
            $table->foreignId('equipment_id')->nullable()->after('priority')->constrained('equipment')->nullOnDelete();
            $table->json('metadata')->nullable()->after('description');
            $table->timestamp('source_triggered_at')->nullable()->after('triggered_at');

            $table->unique(['control_room_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropUnique(['control_room_id', 'source', 'external_id']);
            $table->dropConstrainedForeignId('equipment_id');
            $table->dropColumn(['source', 'external_id', 'priority', 'metadata', 'source_triggered_at']);
        });
    }
};
