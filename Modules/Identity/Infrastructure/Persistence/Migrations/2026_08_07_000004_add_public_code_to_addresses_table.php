<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer-facing address code (`bda-XXXXXX`).
 *
 * Display and search only. The integer `addresses.id` remains the primary key,
 * the ownership link to `users`, the value checkout sends as `address_id`, the
 * input to Shipment selection and local-delivery eligibility, and the binding
 * for the existing address update/delete routes — none of that changes.
 *
 * Nullable → backfill → unique, against `addresses` only. Kept nullable at the
 * database level for the same SQLite table-rebuild reason as the other
 * public-code columns; the unique index is the real backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('public_code', 16)->nullable()->after('id');
        });

        DB::table('addresses')
            ->whereNull('public_code')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('addresses')->where('id', $row->id)->update([
                        'public_code' => PublicCodeGenerator::generateUnique(
                            PublicCodeEntity::Address,
                            static fn (string $candidate): bool => DB::table('addresses')
                                ->where('public_code', $candidate)
                                ->exists(),
                        ),
                    ]);
                }
            });

        Schema::table('addresses', function (Blueprint $table) {
            $table->unique('public_code');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
