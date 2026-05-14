<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add FK from users to mdas now that mdas table exists
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('mda_id')->references('id')->on('mdas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['mda_id']);
        });
    }
};
