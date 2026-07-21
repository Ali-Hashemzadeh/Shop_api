<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            // Nullable on purpose: some notifications are external-only (e.g. an
            // SMS-only event), so there is no in-app notification to attach to.
            $table->foreignId('notification_id')
                ->nullable()
                ->constrained('notifications')
                ->cascadeOnDelete();
            $table->string('channel');
            $table->string('status');
            $table->string('provider')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
