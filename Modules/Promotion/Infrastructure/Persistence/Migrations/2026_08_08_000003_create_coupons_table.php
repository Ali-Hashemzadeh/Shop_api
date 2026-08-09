<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            // The backing rule. Must be trigger_type=coupon + scope=all; enforced at
            // the application layer because the DB cannot express a cross-row check.
            $table->foreignId('discount_id')
                ->constrained('discounts')
                ->cascadeOnDelete();

            // Marketing identifier (SUMMER10, NOWRUZ, …), NOT a PublicCodeGenerator
            // code — customers are handed these deliberately, so they must be
            // memorable rather than opaque. Stored normalized: trimmed + uppercased.
            $table->string('code', 32)->unique();

            $table->boolean('is_active')->default(true);

            // Null means unlimited. Both limits count reserved + redeemed, never released.
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
