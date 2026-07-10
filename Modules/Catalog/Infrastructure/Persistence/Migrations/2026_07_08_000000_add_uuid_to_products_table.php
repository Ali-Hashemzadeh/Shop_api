<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Short opaque public identifier (7 hex chars). A plain string column
            // (not a native `uuid` type) so Postgres accepts the short code.
            $table->string('uuid', 16)->nullable()->after('id');
        });

        // Backfill existing rows so the column is populated everywhere before it is
        // relied upon as the public identifier (no-op on a fresh test database).
        foreach (DB::table('products')->whereNull('uuid')->pluck('id') as $id) {
            do {
                $code = substr(bin2hex(random_bytes(4)), 0, 7);
            } while (DB::table('products')->where('uuid', $code)->exists());

            DB::table('products')->where('id', $id)->update(['uuid' => $code]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
