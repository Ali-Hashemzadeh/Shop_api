<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned prompt templates. A generation stores the exact (name, version) it
 * used, so editing prompts later never rewrites past runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('model');
            $table->unsignedInteger('version')->default(1);
            $table->text('system_prompt');
            $table->text('user_prompt');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'version']);
            $table->index(['name', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }
};
