<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track who uploaded each media asset so consuming modules can enforce
 * per-user ownership (e.g. a customer may only attach their own uploads to a
 * ticket). Loose Identity reference — a nullable `unsignedBigInteger`, no FK —
 * consistent with every other cross-module user reference in the codebase.
 * Nullable and backfilled to null: pre-existing assets and seeder/CLI uploads
 * simply have no owner, which ownership checks treat as "owned by nobody".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['uploaded_by_user_id']);
            $table->dropColumn('uploaded_by_user_id');
        });
    }
};
