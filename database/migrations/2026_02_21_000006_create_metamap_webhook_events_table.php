<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metamap_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('metamap');
            $table->string('event_name')->nullable();
            $table->string('flow_id')->nullable();
            $table->string('verification_id')->nullable();
            $table->string('identity_id')->nullable();
            $table->text('resource')->nullable();
            $table->string('record_id')->nullable();
            $table->unsignedBigInteger('service_request_id')->nullable();
            $table->string('signature')->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->timestamp('event_timestamp')->nullable();
            $table->json('metadata')->nullable();
            $table->json('payload')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['event_name', 'created_at']);
            $table->index(['verification_id', 'identity_id']);
            $table->index(['service_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metamap_webhook_events');
    }
};
