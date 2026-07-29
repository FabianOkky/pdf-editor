<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asynchronous export requests (ARCHITECTURE.md §3/§5). Each row tracks one PDF → DOCX
     * (or future PDF) export run by a queued job: which document, which pipeline engine ran,
     * the lifecycle status, and where the produced file landed. The result is a brand-new
     * artifact — the source document is never modified (the Golden Rule).
     */
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format')->default('docx');
            $table->string('engine')->nullable();
            $table->string('status')->default('queued');
            $table->string('result_path')->nullable();
            $table->string('result_filename')->nullable();
            $table->unsignedInteger('result_size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
