<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // automatic => always live for matching variants (scope must be targeted).
            // coupon    => dormant until a code activates it (scope must be all).
            $table->string('trigger_type', 20);
            $table->string('scope', 20);

            $table->string('discount_type', 20);

            // Integer basis points: 2000 == 20%, 1250 == 12.5%, 10000 == 100%.
            // Percentages are never stored as floats — see DiscountCalculator.
            $table->unsignedInteger('percentage_bps')->nullable();

            // Cents Rule: every money column below is raw integer rials, matching
            // the width of product_variants.base_price / orders.total_amount.
            $table->integer('fixed_amount')->nullable();
            $table->integer('max_discount_amount')->nullable();
            $table->integer('min_subtotal')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            // Explicit operator ordering, used only as a late tie-break when two
            // rules produce the exact same rial reduction.
            $table->integer('priority')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // The automatic-evaluation hot path filters on exactly these columns.
            $table->index(['trigger_type', 'is_active']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
