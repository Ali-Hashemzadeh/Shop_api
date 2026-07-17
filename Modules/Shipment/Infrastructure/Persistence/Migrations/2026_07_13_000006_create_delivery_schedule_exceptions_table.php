<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            // 'closed' | 'custom_hours'
            $table->string('type');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedule_exceptions');
    }
};
