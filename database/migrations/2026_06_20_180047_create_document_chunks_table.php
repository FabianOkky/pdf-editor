<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retrieval chunks for RAG (ARCHITECTURE.md §3, Phase 6). Each row is a slice of a
     * document's extracted text (tagged with the page it came from) plus its embedding.
     *
     * Vector store: the plan's lead was pgvector, but the dev/CI Postgres has no ``vector``
     * extension available, so the embedding is stored as a portable JSON array of floats and
     * similarity is computed in-process (the plan's allowed in-process fallback). Documents are
     * owner-scoped and bounded, so cosine over one document's chunks is cheap. To upgrade later:
     * enable the ``vector`` extension, swap this column to ``vector(N)``, and replace the
     * in-process ranking in RagService with an ``ORDER BY embedding <=> ?`` query.
     */
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
