<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages within an AI conversation (ARCHITECTURE.md §3, Phase 6). ``role`` is the
     * AiMessageRole enum; ``meta`` carries assistant grounding (the page numbers / chunk ids
     * the answer cited) so the UI can show "Sources". AI output stays here — it is never
     * written back onto the PDF layout (the Phase 6 fidelity note).
     */
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->unsignedInteger('tokens')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
