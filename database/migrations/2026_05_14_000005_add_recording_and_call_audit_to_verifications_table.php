<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->string('recording_url')->nullable()->after('verified_at');
            $table->string('vapi_call_id')->nullable()->after('recording_url');
            $table->decimal('call_cost', 8, 4)->nullable()->after('vapi_call_id');
            $table->text('transcript')->nullable()->after('call_cost');

            $table->index('vapi_call_id');
        });
    }

    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->dropIndex(['vapi_call_id']);
            $table->dropColumn(['recording_url', 'vapi_call_id', 'call_cost', 'transcript']);
        });
    }
};
