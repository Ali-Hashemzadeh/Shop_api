<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id');
        });

        // Backfill existing rows so the column is populated everywhere before it is
        // relied upon as the public identifier (no-op on a fresh test database).
        foreach (DB::table('products')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('products')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
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
