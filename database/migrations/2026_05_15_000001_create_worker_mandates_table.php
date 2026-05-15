<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_mandates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('transaction_reference')->unique();
            $table->string('squad_mandate_reference')->nullable();
            $table->string('mandate_type')->default('emandate');
            $table->decimal('amount', 15, 2);
            $table->string('bank_code', 20);
            $table->string('account_number', 20);
            $table->string('account_name')->nullable();
            $table->string('customer_email');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['pending', 'created', 'failed', 'skipped_paid'])->default('pending');
            $table->text('description')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('worker_id');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_mandates');
    }
};
