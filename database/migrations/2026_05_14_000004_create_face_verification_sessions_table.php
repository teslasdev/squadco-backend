<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_verification_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'identity_pending',
                'pose_right_pending',
                'pose_left_pending',
                'completed',
                'failed',
                'expired',
            ])->default('identity_pending');
            $table->string('frame1_url')->nullable();
            $table->string('frame2_url')->nullable();
            $table->string('frame3_url')->nullable();
            $table->tinyInteger('identity_score')->nullable();
            $table->string('identity_verdict')->nullable();
            $table->decimal('identity_spoof_prob', 6, 4)->nullable();
            $table->boolean('pose_right_passed')->nullable();
            $table->boolean('pose_left_passed')->nullable();
            $table->decimal('pose_right_delta_deg', 6, 2)->nullable();
            $table->decimal('pose_left_delta_deg', 6, 2)->nullable();
            $table->string('verdict')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('verification_id')->nullable()->constrained('verifications')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_verification_sessions');
    }
};
