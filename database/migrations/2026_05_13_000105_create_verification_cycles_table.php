<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('cycle_month');
            $table->string('federation')->nullable();
            $table->enum('status', ['pending', 'running', 'completed'])->default('pending');
            $table->integer('total_workers')->default(0);
            $table->integer('verified_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('inconclusive_count')->default(0);
            $table->decimal('payroll_released', 15, 2)->default(0);
            $table->decimal('payroll_blocked', 15, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_cycles');
    }
};
