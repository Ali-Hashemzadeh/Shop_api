<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_discount', function (Blueprint $table) {
            $table->id();

            // Both sides are Promotion-owned, so real FKs are correct.
            $table->foreignId('campaign_id')
                ->constrained('campaigns')
                ->cascadeOnDelete();

            $table->foreignId('discount_id')
                ->constrained('discounts')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['campaign_id', 'discount_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_discount');
    }
};
