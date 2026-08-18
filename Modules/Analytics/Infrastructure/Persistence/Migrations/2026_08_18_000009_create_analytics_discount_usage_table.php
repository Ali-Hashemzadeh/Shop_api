<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_discount_usage', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('discount_id')->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('generated_revenue')->default(0);
            $table->timestamps();

            $table->unique(['date', 'discount_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_discount_usage');
    }
};
