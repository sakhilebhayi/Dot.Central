<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every signed delivery Dot.Mines sent us, stored before it is applied, deduped on its delivery id. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mines_connection_id')->constrained('mines_connections')->cascadeOnDelete();
            $table->string('delivery_id', 64);
            $table->string('event', 64);
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');
            $table->string('status', 16)->default('received');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['mines_connection_id', 'delivery_id']);
            $table->index(['mines_connection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_deliveries');
    }
};
