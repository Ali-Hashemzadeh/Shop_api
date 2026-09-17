<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer product likes (wishlist / favourites).
 *
 * `user_id` and `product_id` are loose references with no foreign keys — the
 * Wishlist module owns neither the users table (Identity) nor the products
 * table (Catalog), and the codebase never cross-module-FKs (see reviews,
 * notifications). Deleting a product or a user therefore leaves any like rows
 * inert rather than cascading; they are harmless and filtered out when the
 * liked-products page can no longer resolve a published product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_likes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            // One like per user per product.
            $table->unique(['user_id', 'product_id']);
            // Serves "my liked products" (newest first) for a given user.
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_likes');
    }
};
