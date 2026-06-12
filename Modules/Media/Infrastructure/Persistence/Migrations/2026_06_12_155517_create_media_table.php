<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id(); // Standard fast auto-incrementing integer

            // The relative path within storage/app/ (e.g., 'public/products/my-image.jpg')
            $table->string('file_path');

            // Optional useful metadata for file headers or asset filtering
            $table->string('mime_type')->nullable(); // e.g., 'image/jpeg'
            $table->unsignedBigInteger('file_size')->nullable(); // Stored in bytes
            $table->string('original_name')->nullable(); // Original client file name

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
