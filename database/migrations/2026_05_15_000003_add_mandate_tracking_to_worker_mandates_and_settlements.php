<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_mandates', function (Blueprint $table) {
            $table->foreignId('settlement_id')->nullable()->after('worker_id')->constrained('settlements')->nullOnDelete();
            $table->boolean('approved')->default(false)->after('status');
            $table->boolean('ready_to_debit')->default(false)->after('approved');
            $table->string('debit_transaction_reference')->nullable()->after('transaction_reference');
            $table->string('last_webhook_event')->nullable()->after('debit_transaction_reference');
            $table->timestamp('debited_at')->nullable()->after('failed_at');

            $table->unique('debit_transaction_reference');
            $table->index('settlement_id');
        });

        DB::statement("ALTER TABLE worker_mandates MODIFY COLUMN status ENUM('pending','created','approved','ready','debiting','debited','failed','skipped_paid') DEFAULT 'pending'");

        Schema::table('settlements', function (Blueprint $table) {
            $table->string('mandate_transaction_reference')->nullable()->after('squad_batch_id');
            $table->string('mandate_id')->nullable()->after('mandate_transaction_reference');
            $table->string('mandate_status')->nullable()->after('mandate_id');
            $table->boolean('mandate_ready_to_debit')->default(false)->after('mandate_status');
            $table->string('mandate_last_event')->nullable()->after('mandate_ready_to_debit');
            $table->json('mandate_last_payload')->nullable()->after('mandate_last_event');
            $table->string('mandate_debit_reference')->nullable()->after('mandate_last_payload');
            $table->timestamp('mandate_debited_at')->nullable()->after('mandate_debit_reference');

            $table->index('mandate_transaction_reference');
            $table->index('mandate_debit_reference');
        });
    }

    public function down(): void
    {
        DB::table('worker_mandates')
            ->whereIn('status', ['approved', 'ready', 'debiting', 'debited'])
            ->update(['status' => 'created']);

        DB::statement("ALTER TABLE worker_mandates MODIFY COLUMN status ENUM('pending','created','failed','skipped_paid') DEFAULT 'pending'");

        Schema::table('worker_mandates', function (Blueprint $table) {
            $table->dropUnique(['debit_transaction_reference']);
            $table->dropIndex(['settlement_id']);
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropColumn([
                'approved',
                'ready_to_debit',
                'debit_transaction_reference',
                'last_webhook_event',
                'debited_at',
            ]);
        });

        Schema::table('settlements', function (Blueprint $table) {
            $table->dropIndex(['mandate_transaction_reference']);
            $table->dropIndex(['mandate_debit_reference']);
            $table->dropColumn([
                'mandate_transaction_reference',
                'mandate_id',
                'mandate_status',
                'mandate_ready_to_debit',
                'mandate_last_event',
                'mandate_last_payload',
                'mandate_debit_reference',
                'mandate_debited_at',
            ]);
        });
    }
};
