<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Notify me when available" requests, one per customer per variant SKU.
 *
 * `user_id` is a loose reference (no FK); `sku` is the exact Catalog/Inventory
 * SKU string (also a loose reference — Inventory itself keys stock by the SKU
 * string, not an id). No current stock is copied here: availability is always
 * recomputed by Inventory.
 *
 * One-shot lifecycle: notified_at is null while active and stamped when the
 * restock notification fires. The unique (user_id, sku) index keeps it to a
 * single row that re-activates on re-subscribe; the (sku, notified_at) index
 * makes the restock listener's "active subscribers for this SKU" lookup cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('sku');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // At most one availability request per user per SKU.
            $table->unique(['user_id', 'sku']);
            // The restock listener: active subscribers for a restocked SKU.
            $table->index(['sku', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_subscriptions');
    }
};
