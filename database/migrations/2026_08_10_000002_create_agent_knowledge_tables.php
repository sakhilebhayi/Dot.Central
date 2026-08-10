<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('source_type'); // pasted, text_file, pdf
            $table->string('original_filename')->nullable();
            $table->timestamps();
            $table->index('team_id');
        });

        Schema::create('agent_agent_knowledge', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_knowledge_id')->constrained('agent_knowledge')->cascadeOnDelete();
            $table->primary(['agent_id', 'agent_knowledge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_agent_knowledge');
        Schema::dropIfExists('agent_knowledge');
    }
};
