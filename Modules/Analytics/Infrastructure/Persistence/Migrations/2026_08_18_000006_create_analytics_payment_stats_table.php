<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_payment_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('gateway');
            $table->unsignedInteger('successful_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->timestamps();

            $table->unique(['date', 'gateway']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_payment_stats');
    }
};
