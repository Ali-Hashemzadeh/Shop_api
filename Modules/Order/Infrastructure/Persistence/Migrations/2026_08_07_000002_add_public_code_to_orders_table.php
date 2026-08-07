<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer-facing order code (`bdo-XXXXXX`) — the number a customer
 * quotes to support instead of the internal auto-increment id.
 *
 * Nullable → backfill → unique. The backfill uses the shared generator against
 * `orders` only; model events are unavailable in migrations, so the model's
 * creating hook plays no part here.
 *
 * The column stays nullable at the database level (same precedent as
 * `products.uuid`): tightening to NOT NULL on SQLite rebuilds the table and can
 * drop the existing `[user_id, status]` index. The application layer guarantees
 * a value on every insert and the unique index is the real integrity backstop.
 *
 * Purely additive: `orders.id` remains the primary key and the target of
 * `order_items.order_id`, payments, shipments, and every internal lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('public_code', 16)->nullable()->after('id');
        });

        DB::table('orders')
            ->whereNull('public_code')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'public_code' => PublicCodeGenerator::generateUnique(
                            PublicCodeEntity::Order,
                            static fn (string $candidate): bool => DB::table('orders')
                                ->where('public_code', $candidate)
                                ->exists(),
                        ),
                    ]);
                }
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->unique('public_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
