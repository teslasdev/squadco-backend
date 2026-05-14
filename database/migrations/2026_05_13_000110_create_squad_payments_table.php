<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squad_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('verification_cycles')->cascadeOnDelete();
            $table->foreignId('verification_id')->nullable()->constrained('verifications')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_masked')->nullable();
            $table->string('squad_reference')->unique();
            $table->enum('status', ['released', 'blocked', 'pending', 'failed'])->default('pending');
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_payments');
    }
};
