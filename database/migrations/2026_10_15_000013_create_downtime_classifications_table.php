<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The one human input on stoppages: a reason code attached to an observed interval. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('downtime_reason_id')->constrained('downtime_reasons')->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_classifications');
    }
};
