<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iran Post 1405 base rates (66 explicit rows, seeded by PostalTariff1405Seeder).
     *
     * One row per (service, zone, weight bracket, tariff year). Brackets are CLOSED in
     * whole grams: weight_from <= weight <= weight_to; weight_to NULL is open-ended and
     * charges extra_per_started_kg_toman for each started kg above extra_above_grams.
     *
     * MONEY UNIT: every *_toman column is integer TOMANS (the PDF's rials ÷ 10).
     *
     * PRODUCTION-SAFE: when the table already exists (hand-made through SQL) it is not
     * recreated, dropped, or truncated; only missing columns are added. The unique rate
     * key is added only when absent AND the data has no duplicates — otherwise the
     * duplicates are left for `shipping:post-1405:verify` to report.
     */
    public function up(): void
    {
        if (! Schema::hasTable('postal_tariffs_1405')) {
            Schema::create('postal_tariffs_1405', function (Blueprint $table) {
                $table->id();
                $table->string('service_code', 90);
                $table->string('zone_code', 30);
                $table->unsignedInteger('weight_from');
                $table->unsignedInteger('weight_to')->nullable();
                $table->unsignedInteger('base_price_toman');
                $table->unsignedInteger('extra_per_started_kg_toman')->default(0);
                $table->unsignedInteger('extra_above_grams')->nullable();
                $table->unsignedSmallInteger('tariff_year')->default(1405);
                $table->timestamps();

                $table->foreign('service_code')->references('code')->on('postal_services_1405');
                $table->unique(['service_code', 'zone_code', 'weight_from', 'tariff_year'], 'postal_tariffs_1405_rate_key');
                $table->index(['tariff_year', 'service_code'], 'postal_tariffs_1405_lookup');
            });

            return;
        }

        $missing = [
            'extra_per_started_kg_toman' => fn (Blueprint $t) => $t->unsignedInteger('extra_per_started_kg_toman')->default(0),
            'extra_above_grams' => fn (Blueprint $t) => $t->unsignedInteger('extra_above_grams')->nullable(),
            'tariff_year' => fn (Blueprint $t) => $t->unsignedSmallInteger('tariff_year')->default(1405),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];

        foreach ($missing as $column => $definition) {
            if (! Schema::hasColumn('postal_tariffs_1405', $column)) {
                Schema::table('postal_tariffs_1405', $definition);
            }
        }

        $hasDuplicates = DB::table('postal_tariffs_1405')
            ->select('service_code', 'zone_code', 'weight_from', 'tariff_year')
            ->groupBy('service_code', 'zone_code', 'weight_from', 'tariff_year')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDuplicates && ! Schema::hasIndex('postal_tariffs_1405', ['service_code', 'zone_code', 'weight_from', 'tariff_year'], 'unique')) {
            Schema::table('postal_tariffs_1405', function (Blueprint $table) {
                $table->unique(['service_code', 'zone_code', 'weight_from', 'tariff_year'], 'postal_tariffs_1405_rate_key');
            });
        }
    }

    /** Deliberately non-destructive — see the services migration. */
    public function down(): void
    {
        //
    }
};
