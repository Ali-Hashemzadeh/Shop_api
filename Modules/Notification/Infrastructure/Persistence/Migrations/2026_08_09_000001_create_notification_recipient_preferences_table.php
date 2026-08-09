<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipient_preferences', function (Blueprint $table) {
            $table->id();
            // Plain reference, like `notifications.user_id` — Notification does not
            // own the users table and never joins against it. Whether an id still
            // names an admin is answered by Identity's contract, not by an FK.
            $table->unsignedBigInteger('user_id');
            // Deliberately generic: one row is "user X does/does not want
            // notification type Y on channel Z". The paid-order SMS is the first
            // use, not the only shape the table can hold.
            $table->string('notification_type');
            $table->string('channel');
            $table->boolean('enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'notification_type', 'channel'], 'notif_recipient_pref_unique');
            // Serves the send path: "who is enabled for this type on this channel".
            $table->index(['notification_type', 'channel', 'enabled'], 'notif_recipient_pref_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipient_preferences');
    }
};
