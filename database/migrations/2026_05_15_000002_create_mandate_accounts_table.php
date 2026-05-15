<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandate_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 20);
            $table->string('bank_code', 20);
            $table->string('description')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_primary', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandate_accounts');
    }
};
