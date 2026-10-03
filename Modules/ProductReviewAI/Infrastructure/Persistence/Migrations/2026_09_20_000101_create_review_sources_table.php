<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registered external marketplaces. `driver` holds a stable code (e.g. digikala)
 * resolved to a concrete adapter by ReviewSourceFactory — never a PHP namespace,
 * so the DB stays decoupled from class names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('driver');
            $table->json('config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_sources');
    }
};
