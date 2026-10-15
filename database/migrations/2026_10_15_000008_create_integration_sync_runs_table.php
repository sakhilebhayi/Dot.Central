<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per synchronisation attempt: the observability record the Health page reads. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mines_connection_id')->constrained('mines_connections')->cascadeOnDelete();
            $table->string('trigger', 32)->default('schedule');
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('cursor_before')->nullable();
            $table->unsignedBigInteger('cursor_after')->nullable();
            $table->unsignedInteger('fetched_machines')->default(0);
            $table->unsignedInteger('upserted')->default(0);
            $table->unsignedInteger('retired')->default(0);
            $table->unsignedInteger('alerts_upserted')->default(0);
            $table->unsignedInteger('hours_updated')->default(0);
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['mines_connection_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_runs');
    }
};
