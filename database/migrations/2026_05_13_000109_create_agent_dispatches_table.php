<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('ghost_worker_alerts')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('field_agents')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->enum('status', ['pending', 'en_route', 'completed', 'failed'])->default('pending');
            $table->decimal('gps_lat', 10, 8)->nullable();
            $table->decimal('gps_lng', 11, 8)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_dispatches');
    }
};
