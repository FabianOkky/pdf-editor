<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Page metadata describing each produced version, so the versions UI can show page
     * counts/sizes without re-opening the file. Page operations (Phase 2) fill these in.
     */
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->unsignedInteger('page_count')->default(0)->after('path');
            $table->unsignedBigInteger('size_bytes')->default(0)->after('page_count');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $table) {
            $table->dropColumn(['page_count', 'size_bytes']);
        });
    }
};
