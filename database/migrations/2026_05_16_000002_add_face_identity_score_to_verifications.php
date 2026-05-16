<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the RAW face identity match score on the verification record itself.
 *
 * The dashboard lists `verifications`, which until now only had `trust_score`
 * (the discrete verdict sentinel: 10/15=FAIL, 50/55=INCONCLUSIVE, 75+=PASS)
 * and `face_liveness_score` (which was actually being fed the same sentinel).
 * Neither carried the real ArcFace identity match — so a near-perfect 0.94
 * face match was being shown to reviewers as "55".
 *
 * `face_identity_score` = round(cosine * 100), 0-100. It answers "is this the
 * right face?" independently of liveness/verdict, so the UI can show a strong
 * identity (~94) while liveness/verdict remain their own separate gates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->unsignedTinyInteger('face_identity_score')
                ->nullable()
                ->after('face_liveness_score');
        });
    }

    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->dropColumn('face_identity_score');
        });
    }
};
