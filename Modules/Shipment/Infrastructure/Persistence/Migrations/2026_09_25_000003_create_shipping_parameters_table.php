<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tunable formula constants for the shipping calculators, keyed by carrier + key.
     *
     * Every numeric constant the Post calculator applies (surcharge percentages, the
     * island per-kg fee) lives here, never in PHP — so an operator retunes pricing
     * with a row edit, not a deploy. Values are stored as strings and cast on read
     * according to `type`, so one table can hold integers, percentages, and flags.
     *
     * MONEY UNIT: amount-typed values are TOMANS (integers, Money Unit Rule).
     */
    public function up(): void
    {
        Schema::create('shipping_parameters', function (Blueprint $table) {
            $table->id();
            $table->string('carrier')->index();
            $table->string('key');
            // Stored as text; interpreted per `type` on read (never a float column).
            $table->string('value');
            // integer_toman | percentage | integer | string — how `value` is cast.
            $table->string('type')->default('integer');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['carrier', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_parameters');
    }
};
