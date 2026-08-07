<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer-facing category code (`bdc-XXXXXX`).
 *
 * Nullable → backfill → unique, in that order: the unique index cannot be added
 * before every existing row has a value, and the backfill cannot rely on the
 * model's creating hook because migrations run without model events. It uses
 * the shared generator against this table only — no cross-module access.
 *
 * The column stays nullable at the database level, matching the precedent set by
 * `products.uuid`: on SQLite (used for tests and local development) tightening a
 * column to NOT NULL rebuilds the table and can silently drop indexes. The
 * application layer guarantees the value on every write, and the unique index is
 * the real integrity backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('public_code', 16)->nullable()->after('id');
        });

        DB::table('categories')
            ->whereNull('public_code')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('categories')->where('id', $row->id)->update([
                        'public_code' => PublicCodeGenerator::generateUnique(
                            PublicCodeEntity::Category,
                            static fn (string $candidate): bool => DB::table('categories')
                                ->where('public_code', $candidate)
                                ->exists(),
                        ),
                    ]);
                }
            });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique('public_code');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
