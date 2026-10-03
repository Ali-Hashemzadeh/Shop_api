<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer support ticket.
 *
 * `user_id` (customer) and `assigned_to` (support agent) are loose Identity
 * references — no FK, resolved through IdentityManagerInterface — exactly like
 * `user_id` in reviews. `category` stores a ticket_categories.code, not an id.
 * The public handle is `ticket_number` (`bdk-XXXXXX`), nullable at the DB level
 * for the same SQLite table-rebuild reason as the other public-code columns; the
 * application layer always assigns it and the unique index backstops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 16)->nullable()->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->string('subject');
            $table->string('category')->nullable();
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index('status');
            $table->index('priority');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
