<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('ippis_id')->unique();
            $table->string('full_name');
            $table->string('nin')->nullable();
            $table->string('bvn')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('mda_id')->constrained('mdas')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('grade_level')->nullable();
            $table->string('step')->nullable();
            $table->string('state_of_posting')->nullable();
            $table->decimal('salary_amount', 15, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->enum('status', ['active', 'flagged', 'blocked', 'suspended'])->default('active');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->string('face_template_url')->nullable();
            $table->string('voice_template_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
