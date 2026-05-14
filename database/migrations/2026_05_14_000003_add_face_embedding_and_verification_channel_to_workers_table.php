<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->json('face_embedding')->nullable()->after('face_template_url');
            $table->enum('verification_channel', ['phone', 'web', 'both'])
                  ->default('phone')
                  ->after('voice_embedding_campplus');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['face_embedding', 'verification_channel']);
        });
    }
};
