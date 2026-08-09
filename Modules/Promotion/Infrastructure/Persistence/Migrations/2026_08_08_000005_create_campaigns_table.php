<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Landing-page sections are an explicit editorial opt-in: an active
            // campaign is still reachable by slug without being merchandised on the
            // home page.
            $table->boolean('show_on_landing')->default(false);
            $table->integer('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'show_on_landing']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
