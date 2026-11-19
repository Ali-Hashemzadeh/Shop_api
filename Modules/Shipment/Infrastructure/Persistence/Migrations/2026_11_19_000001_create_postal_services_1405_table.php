<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iran Post 1405 service definitions (21 rows, seeded by PostalService1405Seeder).
     *
     * PRODUCTION-SAFE: production may already hold this table, created by hand through
     * SQL. In that case nothing is recreated or dropped — only columns that are missing
     * are added (all nullable / defaulted), so existing rows are never touched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('postal_services_1405')) {
            Schema::create('postal_services_1405', function (Blueprint $table) {
                $table->string('code', 90)->primary();
                $table->string('title_fa', 255);
                $table->string('scope', 30);
                $table->string('content_kind', 40);
                $table->string('package_kind', 25);
                $table->string('rating_rule', 35);
                $table->string('pdf_reference', 60);
                $table->string('eligibility_policy', 35);
                $table->unsignedSmallInteger('tariff_year')->default(1405);
                $table->timestamps();
            });

            return;
        }

        $missing = [
            'title_fa' => fn (Blueprint $t) => $t->string('title_fa', 255)->nullable(),
            'scope' => fn (Blueprint $t) => $t->string('scope', 30)->nullable(),
            'content_kind' => fn (Blueprint $t) => $t->string('content_kind', 40)->nullable(),
            'package_kind' => fn (Blueprint $t) => $t->string('package_kind', 25)->nullable(),
            'rating_rule' => fn (Blueprint $t) => $t->string('rating_rule', 35)->nullable(),
            'pdf_reference' => fn (Blueprint $t) => $t->string('pdf_reference', 60)->nullable(),
            'eligibility_policy' => fn (Blueprint $t) => $t->string('eligibility_policy', 35)->nullable(),
            'tariff_year' => fn (Blueprint $t) => $t->unsignedSmallInteger('tariff_year')->default(1405),
            'created_at' => fn (Blueprint $t) => $t->timestamp('created_at')->nullable(),
            'updated_at' => fn (Blueprint $t) => $t->timestamp('updated_at')->nullable(),
        ];

        foreach ($missing as $column => $definition) {
            if (! Schema::hasColumn('postal_services_1405', $column)) {
                Schema::table('postal_services_1405', $definition);
            }
        }
    }

    /**
     * Deliberately non-destructive: tariff catalogs are never dropped by a rollback.
     * Remove the table by hand only after confirming no order snapshot depends on it.
     */
    public function down(): void
    {
        //
    }
};
