<?php

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the customer-facing payment code (`bdt-XXXXXX`).
 *
 * This is a *support reference*, not a gateway identifier: `transaction_reference`
 * (the Zarinpal authority / RefID) is untouched and remains the value used for
 * callback lookup, verification, and idempotency. The two are shown side by side
 * on the result page and never substituted for one another.
 *
 * Nullable → backfill → unique, using the shared generator against `payments`
 * only. Kept nullable at the database level for the same SQLite table-rebuild
 * reason as the other public-code columns; the unique index is the real backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('public_code', 16)->nullable()->after('id');
        });

        DB::table('payments')
            ->whereNull('public_code')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('payments')->where('id', $row->id)->update([
                        'public_code' => PublicCodeGenerator::generateUnique(
                            PublicCodeEntity::Payment,
                            static fn (string $candidate): bool => DB::table('payments')
                                ->where('public_code', $candidate)
                                ->exists(),
                        ),
                    ]);
                }
            });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('public_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
