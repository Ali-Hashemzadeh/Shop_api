<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Post tariff brackets — the price table the calculator queries. Tariffs are data,
     * never PHP constants, so pricing changes without a deploy.
     *
     * A row is a weight bracket for a (service_type, package_type, distance_type)
     * combination. Matching is HALF-OPEN on weight: weight_from <= weight < weight_to,
     * with weight_to = null meaning the open-ended top bracket.
     *
     *   - base_price          flat price (TOMANS) for a parcel inside the bracket
     *   - extra_weight_price  per-additional-kg price (TOMANS, rounded up) charged only
     *                         on the open-ended top bracket, for weight above weight_from
     *   - effective_from/until optional validity window (null = always in effect)
     *
     * MONEY UNIT: base_price and extra_weight_price are TOMANS (integers).
     */
    public function up(): void
    {
        Schema::create('post_tariffs', function (Blueprint $table) {
            $table->id();
            // Matches a shipment method code (e.g. post_standard / post_express).
            $table->string('service_type');
            $table->string('package_type')->default('standard');
            // Weight bracket in whole grams (half-open [from, to); to null = open-ended).
            $table->unsignedInteger('weight_from')->default(0);
            $table->unsignedInteger('weight_to')->nullable();
            // same_province | neighbor | non_neighbor.
            $table->string('distance_type');
            $table->integer('base_price');
            $table->integer('extra_weight_price')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['service_type', 'package_type', 'distance_type'], 'post_tariffs_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_tariffs');
    }
};
