<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('verification_cycles')->cascadeOnDelete();
            $table->enum('channel', ['ivr', 'app', 'agent']);
            $table->tinyInteger('trust_score')->default(0);
            $table->enum('verdict', ['PASS', 'FAIL', 'INCONCLUSIVE'])->nullable();
            $table->tinyInteger('challenge_response_score')->nullable();
            $table->tinyInteger('speaker_biometric_score')->nullable();
            $table->tinyInteger('anti_spoof_score')->nullable();
            $table->tinyInteger('replay_detection_score')->nullable();
            $table->tinyInteger('face_liveness_score')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->enum('language', ['yoruba', 'hausa', 'igbo', 'pidgin', 'english'])->nullable();
            $table->boolean('salary_released')->default(false);
            $table->string('squad_reference')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
