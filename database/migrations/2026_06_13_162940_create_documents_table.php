<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The immutable original upload plus its metadata. Edits are never written back here;
     * they live as overlays/versions (see ARCHITECTURE.md, the Golden Rule).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('original_filename');
            $table->string('disk')->default('pdfs');
            $table->string('path');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime')->default('application/pdf');
            $table->string('source_type')->default('unknown'); // native|scanned|mixed|unknown
            $table->string('status')->default('ready');         // ready|processing|failed
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
