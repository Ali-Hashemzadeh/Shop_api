<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password-based auth coexists with passwordless OTP: a user may own an
     * account without ever setting a password. This migration guarantees the
     * `password` column exists and is nullable.
     *
     * On the current schema the column already exists (created in
     * create_users_table, made nullable in refactor_users_table_for_otp_auth),
     * so this is a defensive no-op that keeps fresh installs and partial
     * schemas consistent.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password')) {
                $table->string('password')->nullable()->after('phone');

                return;
            }

            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No-op: the column predates this migration and is owned by the
        // users-table create/refactor migrations. Reversing here would drop a
        // column this migration did not create.
    }
};
