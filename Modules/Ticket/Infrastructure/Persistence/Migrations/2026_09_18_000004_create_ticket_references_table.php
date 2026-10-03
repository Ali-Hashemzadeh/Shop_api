<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loose pointers from a ticket to entities in other modules (an order, product,
 * …). `ticket_id` cascades (own module). `reference_type` is an enum whitelist,
 * `reference_code` the public code the customer quoted, `reference_id` an
 * optional numeric id. There is deliberately NO foreign key to any other
 * module's table — Ticket never resolves or validates the target, keeping the
 * module fully decoupled. `snapshot` may hold a denormalized copy if ever needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('reference_type');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_code')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'reference_type']);
            $table->index(['reference_type', 'reference_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_references');
    }
};
