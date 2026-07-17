<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->id();
            $table->date('delivery_date')->index();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('admin_reserved_capacity')->default(0);
            $table->string('status')->default('open')->index();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['delivery_date', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_slots');
    }
};
