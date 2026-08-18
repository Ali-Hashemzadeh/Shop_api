<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_delivery_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('method');
            $table->unsignedInteger('assigned_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('total_delivery_minutes')->default(0);
            $table->timestamps();

            $table->unique(['date', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_delivery_stats');
    }
};
