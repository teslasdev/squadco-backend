<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the RAW ArcFace cosine similarity (and its 0-100 layer score)
 * alongside the existing bucketed `identity_score` sentinel.
 *
 * Before this, only the fused sentinel (e.g. 55/INCONCLUSIVE, 10/FAIL) was
 * stored — the actual cosine the verdict was derived from was discarded,
 * which made it impossible to diagnose or tune face-match quality after
 * the fact. These columns close that blind spot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('face_verification_sessions', function (Blueprint $table) {
            // Raw ArcFace cosine vs the enrolled embedding. Range roughly
            // [-1, 1]; genuine same-person pairs usually 0.6-0.85.
            $table->decimal('identity_similarity', 8, 6)->nullable()->after('identity_score');
            // layers.identity from the AI = cosine * 100, clamped 0-100.
            $table->unsignedTinyInteger('identity_layer_score')->nullable()->after('identity_similarity');
        });
    }

    public function down(): void
    {
        Schema::table('face_verification_sessions', function (Blueprint $table) {
            $table->dropColumn(['identity_similarity', 'identity_layer_score']);
        });
    }
};
