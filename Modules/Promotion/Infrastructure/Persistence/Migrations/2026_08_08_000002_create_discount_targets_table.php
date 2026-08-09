<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_targets', function (Blueprint $table) {
            $table->id();

            // Same-module FK: discounts is owned by Promotion, so a real constraint
            // is correct here.
            $table->foreignId('discount_id')
                ->constrained('discounts')
                ->cascadeOnDelete();

            // product | variant | category | brand. There is no `all` target type —
            // store-wide reach lives on discounts.scope.
            $table->string('target_type', 20);

            // Deliberately a loose primitive reference into Catalog, with NO foreign
            // key. Promotion must not depend on Catalog (Catalog depends on Promotion
            // for live pricing, so an FK either way would close the cycle), and it
            // never calls Catalog to verify existence. A target naming a deleted
            // Catalog row simply stops matching and becomes inert.
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            $table->unique(['discount_id', 'target_type', 'target_id'], 'discount_targets_unique');
            // Drives both the per-variant candidate lookup and the has_discount /
            // campaign target-definition reads.
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_targets');
    }
};
