<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refactor the users table for passwordless OTP authentication.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Passwordless: a password is no longer required to own an account.
            $table->string('password')->nullable()->change();

            // Accounts can be created from just a phone number during the OTP
            // sign-up flow, so a display name is no longer mandatory up front.
            $table->string('name')->nullable()->change();

            // Single active OTP credential per user (request → verify login flow).
            $table->string('otp_code')->nullable()->after('password');
            $table->timestamp('otp_expires_at')->nullable()->after('otp_code');

            // Loose, FK-free profile image reference (Media coupling rule).
            $table->unsignedBigInteger('media_id')->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['otp_code', 'otp_expires_at', 'media_id']);
            $table->string('password')->nullable(false)->change();
            $table->string('name')->nullable(false)->change();
        });
    }
};
