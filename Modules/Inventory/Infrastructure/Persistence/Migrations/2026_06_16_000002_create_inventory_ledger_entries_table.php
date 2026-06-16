<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('sku');
            $table->enum('type', ['restock', 'sale', 'allocation', 'release', 'adjustment', 'return']);
            $table->integer('quantity_change');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ledger_entries');
    }
};
