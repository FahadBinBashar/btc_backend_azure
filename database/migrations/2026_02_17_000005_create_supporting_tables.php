<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('msisdn');
            $table->string('code_hash');
            $table->string('channel')->default('sms');
            $table->string('status')->default('sent');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['msisdn', 'status']);
        });

        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('hours')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sim_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_request_id')->nullable();
            $table->string('msisdn');
            $table->string('allocation_type')->default('new');
            $table->string('sim_type');
            $table->string('esim_lpa_code')->nullable();
            $table->string('esim_qr_path')->nullable();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->string('pickup_reference')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('registration_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_request_id');
            $table->string('plot_number')->nullable();
            $table->string('ward')->nullable();
            $table->string('village')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_address')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_relation')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('service_request_id')->nullable();
            $table->string('action');
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('registration_profiles');
        Schema::dropIfExists('sim_allocations');
        Schema::dropIfExists('shops');
        Schema::dropIfExists('otp_challenges');
    }
};
