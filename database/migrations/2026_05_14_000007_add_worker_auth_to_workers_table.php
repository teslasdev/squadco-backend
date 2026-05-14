<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds first-class authentication to the workers table.
 *
 *   - `password`        — hashed bcrypt, nullable until the worker creates an account
 *   - `activation_code` — 8-char alphanumeric code admin issues on activate; consumed at signup
 *   - `activation_code_issued_at` — for expiry checks (codes valid for 14 days)
 *   - `account_created_at` — timestamp of first successful signup
 *   - `last_login_at`    — telemetry
 *   - `remember_token`   — standard Laravel column for Sanctum / session refresh
 *
 * Why on `workers` directly (not a separate `worker_logins` table)?
 *   - 1-to-1 relationship, no benefit to normalising.
 *   - The Worker model already has email/phone/ippis_id — the natural login keys.
 *   - Simpler joins for the worker portal (`workers.email` IS the login email).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->string('activation_code', 16)->nullable()->unique()->after('password');
            $table->timestamp('activation_code_issued_at')->nullable()->after('activation_code');
            $table->timestamp('account_created_at')->nullable()->after('activation_code_issued_at');
            $table->timestamp('last_login_at')->nullable()->after('account_created_at');
            $table->rememberToken();

            $table->index('activation_code');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropIndex(['activation_code']);
            $table->dropColumn([
                'password',
                'activation_code',
                'activation_code_issued_at',
                'account_created_at',
                'last_login_at',
                'remember_token',
            ]);
        });
    }
};
