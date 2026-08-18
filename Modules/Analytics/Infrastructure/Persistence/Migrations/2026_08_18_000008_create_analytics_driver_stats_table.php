<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_driver_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedBigInteger('driver_id')->index();
            $table->unsignedInteger('assigned_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('total_delivery_minutes')->default(0);
            $table->timestamps();

            $table->unique(['date', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_driver_stats');
    }
};
