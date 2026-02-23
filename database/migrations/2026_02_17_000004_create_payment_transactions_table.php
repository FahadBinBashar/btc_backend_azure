<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_request_id')->nullable();
            $table->string('msisdn')->nullable();
            $table->string('payment_method');
            $table->string('payment_type')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('BWP');
            $table->string('status')->default('pending');
            $table->string('voucher_code')->nullable();
            $table->string('customer_care_user_id')->nullable();
            $table->string('service_type')->nullable();
            $table->string('plan_name')->nullable();
            $table->json('metadata')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['status', 'service_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
