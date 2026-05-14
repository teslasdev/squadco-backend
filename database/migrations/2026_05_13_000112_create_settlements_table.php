<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('verification_cycles')->cascadeOnDelete();
            $table->decimal('total_disbursed', 15, 2)->default(0);
            $table->decimal('total_blocked', 15, 2)->default(0);
            $table->string('squad_batch_id')->nullable();
            $table->enum('status', ['pending', 'settled', 'failed'])->default('pending');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
