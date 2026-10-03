<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Message attachments. A message references pre-uploaded Media assets by id —
 * a JSON array of ids (loose coupling, no FK into `media`), exactly like
 * `reviews.gallery_media_ids`. The DB does not track storage drives, and a
 * deleted asset simply drops out of the resolved attachment list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->json('media_ids')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropColumn('media_ids');
        });
    }
};
