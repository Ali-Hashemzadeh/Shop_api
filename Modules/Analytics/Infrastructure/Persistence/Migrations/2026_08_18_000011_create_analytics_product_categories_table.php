<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('category_id')->index();
            $table->timestamps();

            $table->unique(['product_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_product_categories');
    }
};
