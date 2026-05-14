<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('state');
            $table->enum('ministry_type', ['federal', 'state']);
            $table->string('contact_email');
            $table->string('head_name');
            $table->integer('worker_count')->default(0);
            $table->decimal('risk_score', 5, 2)->default(0.00);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdas');
    }
};
