<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site scope: which team members work in which control room. Managers
 * and administrators see every room of their team regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_room_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['control_room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_room_user');
    }
};
