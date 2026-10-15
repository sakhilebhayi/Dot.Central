<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Engine-hour readings fetched from Dot.Mines metrics, kept for shift carry-forward and trends. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_hour_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->decimal('hours', 12, 2);
            $table->timestamp('recorded_at');
            $table->string('source', 48)->default('dot_mines.metrics');
            $table->timestamp('ingested_at')->nullable();
            $table->timestamps();

            $table->unique(['equipment_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_hour_readings');
    }
};
