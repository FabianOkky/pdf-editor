<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The structured edit operations for a document. This table is the source of truth for
     * an edited document: each row is one overlay (text, whiteout, shape, …) with geometry in
     * PDF user space. Edits are baked into a flattened version on demand; the original bytes
     * are never modified (the Golden Rule, ARCHITECTURE.md §0/§3).
     */
    public function up(): void
    {
        Schema::create('document_overlays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('type');
            $table->json('payload');
            $table->integer('z_index')->default(0);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_overlays');
    }
};
