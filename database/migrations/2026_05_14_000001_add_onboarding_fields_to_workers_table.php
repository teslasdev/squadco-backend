<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the status enum to include 'draft' (for in-progress onboarding workers)
        DB::statement("ALTER TABLE workers MODIFY COLUMN status ENUM('draft','active','flagged','blocked','suspended') DEFAULT 'active'");

        Schema::table('workers', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('full_name');
            $table->enum('gender', ['male', 'female'])->nullable()->after('date_of_birth');
            $table->date('employment_date')->nullable()->after('gender');
            $table->string('job_title')->nullable()->after('department_id');
            $table->enum('employment_type', ['permanent', 'contract', 'secondment', 'casual'])->nullable()->after('job_title');
            $table->string('lga')->nullable()->after('state_of_posting');
            $table->string('office_address')->nullable()->after('lga');
            $table->string('home_address')->nullable()->after('office_address');
            $table->string('next_of_kin_name')->nullable()->after('home_address');
            $table->string('next_of_kin_phone')->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin_phone');
            $table->string('bank_code')->nullable()->after('bank_account_number');
            $table->string('bank_account_name')->nullable()->after('bank_code');
            $table->boolean('face_enrolled')->default(false)->after('face_template_url');
            $table->boolean('voice_enrolled')->default(false)->after('voice_template_url');
            $table->enum('onboarding_status', ['draft', 'step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'completed'])
                  ->default('draft')
                  ->after('status');
            $table->string('onboarding_token')->nullable()->unique()->after('onboarding_status');
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['onboarding_token']);
            $table->dropColumn([
                'date_of_birth', 'gender', 'employment_date',
                'job_title', 'employment_type',
                'lga', 'office_address', 'home_address',
                'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
                'bank_code', 'bank_account_name',
                'face_enrolled', 'voice_enrolled',
                'onboarding_status', 'onboarding_token',
            ]);
        });

        DB::statement("ALTER TABLE workers MODIFY COLUMN status ENUM('active','flagged','blocked','suspended') DEFAULT 'active'");
    }
};
