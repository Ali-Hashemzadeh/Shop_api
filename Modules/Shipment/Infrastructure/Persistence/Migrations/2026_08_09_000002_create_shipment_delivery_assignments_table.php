<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit of who was responsible for a delivery and when.
 *
 * `shipments.assigned_delivery_user_id` answers "who has it now"; this table
 * answers "who had it, and who handed it to them" — the question that matters
 * after a disputed delivery. A reassignment closes the open row and opens a new
 * one, so the open row is always the current assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_delivery_assignments', function (Blueprint $table) {
            $table->id();

            // Same-module FK: the assignment history has no meaning without its
            // shipment, and both tables belong to this module.
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();

            // Loose Identity references (no cross-module FKs).
            $table->unsignedBigInteger('delivery_user_id')->index();
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();

            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();

            $table->timestamps();

            // The driver's own history view and the "current open row" lookup.
            $table->index(['shipment_id', 'unassigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_delivery_assignments');
    }
};
