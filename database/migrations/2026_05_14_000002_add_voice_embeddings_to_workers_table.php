<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->json('voice_embedding_ecapa')->nullable()->after('voice_template_url');
            $table->json('voice_embedding_campplus')->nullable()->after('voice_embedding_ecapa');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['voice_embedding_ecapa', 'voice_embedding_campplus']);
        });
    }
};
