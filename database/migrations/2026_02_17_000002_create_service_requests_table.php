<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_type');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('msisdn')->nullable();
            $table->string('status')->default('started');
            $table->string('current_step')->nullable();
            $table->boolean('otp_skipped')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['request_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
