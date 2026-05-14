<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the workers.status enum to include the self-enrol + review states.
        // MySQL-only syntax (the project is MySQL-bound per earlier setup).
        DB::statement("ALTER TABLE workers MODIFY COLUMN status ENUM(
            'draft',
            'pending_self_enrol',
            'pending_review',
            'rejected',
            'active',
            'flagged',
            'blocked',
            'suspended'
        ) DEFAULT 'active'");
    }

    public function down(): void
    {
        // Migrate any in-flight workers back to a legacy status before shrinking the enum.
        DB::statement("UPDATE workers SET status='draft' WHERE status IN ('pending_self_enrol', 'pending_review', 'rejected')");

        DB::statement("ALTER TABLE workers MODIFY COLUMN status ENUM(
            'draft',
            'active',
            'flagged',
            'blocked',
            'suspended'
        ) DEFAULT 'active'");
    }
};
