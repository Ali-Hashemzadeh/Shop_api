<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('status')->default('pending');
            $table->integer('total_amount');
            $table->integer('shipping_cost')->default(0);
            $table->integer('tax_amount')->default(0);
            $table->unsignedBigInteger('shipment_method_id')->nullable();
            $table->json('shipping_address');
            $table->string('transaction_ref')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
