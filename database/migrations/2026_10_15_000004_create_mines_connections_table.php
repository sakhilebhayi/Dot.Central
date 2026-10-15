<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Dot.Mines connection per control room: which Dot.Mines team it maps
 * to, the credential (encrypted at rest), the provider cadence used to
 * judge freshness, and the sync state. See docs/dot-central/04-dot-mines-integration.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mines_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url');
            $table->unsignedBigInteger('mines_team_id')->nullable();
            $table->text('api_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->unsignedInteger('provider_cadence_seconds')->default(900);
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 32)->default('unverified');
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedBigInteger('sync_cursor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mines_connections');
    }
};
