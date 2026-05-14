<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ghost_worker_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('verification_id')->nullable()->constrained('verifications')->nullOnDelete();
            $table->enum('alert_type', [
                'synthetic_voice', 'replay_attack', 'biometric_mismatch',
                'face_mismatch', 'duplicate_voiceprint', 'geographic_impossibility',
                'post_mortem_salary', 'consecutive_no_show'
            ]);
            $table->enum('severity', ['critical', 'high', 'medium', 'low']);
            $table->decimal('ai_confidence', 5, 2)->default(0);
            $table->decimal('salary_at_risk', 15, 2)->default(0);
            $table->enum('status', [
                'open', 'blocked', 'agent_dispatched',
                'referred_icpc', 'false_positive', 'resolved'
            ])->default('open');
            $table->text('notes')->nullable();
            $table->timestamp('raised_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ghost_worker_alerts');
    }
};
