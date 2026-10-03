<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One entry in a ticket conversation. `ticket_id` cascades — messages belong to
 * their ticket and are owned entirely within this module. `user_id` is a loose
 * Identity reference (nullable for system messages). `type` decides visibility:
 * an `internal_note` is never returned on a customer surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('message');
            $table->string('type');
            $table->timestamps();

            $table->index(['ticket_id', 'type']);
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
