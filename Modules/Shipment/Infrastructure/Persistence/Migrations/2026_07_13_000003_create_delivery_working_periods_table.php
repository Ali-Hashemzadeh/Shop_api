<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_working_periods', function (Blueprint $table) {
            $table->id();
            // 0 (Sunday) .. 6 (Saturday), matching Carbon::dayOfWeek.
            $table->unsignedTinyInteger('weekday')->index();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_working_periods');
    }
};
