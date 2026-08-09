<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stale_session_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hours_silent');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stale_session_proposals');
    }
};
