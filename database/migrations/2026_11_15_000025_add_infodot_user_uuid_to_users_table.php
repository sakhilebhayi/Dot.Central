<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stable name InfoDot gives a person (R-52). An email can change hands; this uuid cannot, so sign-in from
 * InfoDot matches on it first. Nullable, so every existing row stays valid, and unique, so one InfoDot person can
 * never be linked to two accounts here. Guarded like the teams column before it, because the shared MySQL used in
 * development may already hold it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'infodot_user_uuid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('infodot_user_uuid', 36)->nullable()->unique();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'infodot_user_uuid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['infodot_user_uuid']);
                $table->dropColumn('infodot_user_uuid');
            });
        }
    }
};
