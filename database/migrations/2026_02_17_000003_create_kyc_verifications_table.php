<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_request_id');
            $table->string('provider')->default('metamap');
            $table->string('session_id')->nullable();
            $table->string('verification_id')->nullable();
            $table->string('identity_id')->nullable();
            $table->string('status')->default('pending');
            $table->string('document_type')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('surname')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex')->nullable();
            $table->string('country')->nullable();
            $table->string('document_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
            $table->index(['service_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};
