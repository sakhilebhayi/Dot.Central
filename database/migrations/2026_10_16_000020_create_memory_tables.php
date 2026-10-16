<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url');
            $table->text('api_token')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('status', 32)->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_write_at')->nullable();
            $table->timestamp('last_context_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('event_id')->nullable()->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'sent_at', 'next_attempt_at']);
        });

        Schema::create('memory_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('incident_uid')->unique();
            $table->string('signature');
            $table->string('component');
            $table->string('severity', 8);
            $table->string('status', 32);
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'signature', 'component', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_incidents');
        Schema::dropIfExists('memory_outbox');
        Schema::dropIfExists('memory_connections');
    }
};
