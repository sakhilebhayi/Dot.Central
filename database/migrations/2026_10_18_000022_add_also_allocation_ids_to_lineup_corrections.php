<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-29 slice 2: a loading unit on linked faces moves off or onto several faces
 * at one moment, so a correction of that moment can move more than one other
 * stint. partner_allocation_id keeps the first; this keeps the rest. Plain,
 * additive column: safe on SQLite (production) and Postgres (local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lineup_corrections', function (Blueprint $table) {
            $table->json('also_allocation_ids')->nullable()->after('partner_allocation_id');
        });
    }

    public function down(): void
    {
        Schema::table('lineup_corrections', function (Blueprint $table) {
            $table->dropColumn('also_allocation_ids');
        });
    }
};
