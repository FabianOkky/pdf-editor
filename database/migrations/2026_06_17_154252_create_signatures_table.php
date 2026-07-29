<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reusable signatures a user can place on any of their documents. The image is stored
     * inline as a PNG data URL (signatures are small); placing one copies its bytes into a
     * `signature` overlay, which the bake pipeline flattens like any image. Owner-scoped.
     */
    public function up(): void
    {
        Schema::create('signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->longText('data'); // a PNG data URL (or raw base64)
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signatures');
    }
};
