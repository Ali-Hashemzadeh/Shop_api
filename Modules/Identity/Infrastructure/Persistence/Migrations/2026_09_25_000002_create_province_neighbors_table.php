<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Province adjacency, database-driven (never hardcoded in application logic).
     *
     * Identity owns the "shipping location matrices" (provinces / cities), so the
     * adjacency between provinces lives here too. The Shipment module never queries
     * this table directly — it asks Identity to classify a distance through
     * IdentityManagerInterface::getProvinceDistanceType(), keeping the module wall
     * intact.
     *
     * The relation is stored as a plain pair of province ids. It is seeded in both
     * directions by ProvinceNeighborSeeder, and the distance classifier also matches
     * either direction, so a one-directional row still resolves correctly.
     */
    public function up(): void
    {
        Schema::create('province_neighbors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('provinces')->cascadeOnDelete();
            $table->foreignId('neighbor_province_id')->constrained('provinces')->cascadeOnDelete();
            $table->timestamps();

            // A province is listed against a given neighbour at most once.
            $table->unique(['province_id', 'neighbor_province_id']);
            $table->index('neighbor_province_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('province_neighbors');
    }
};
