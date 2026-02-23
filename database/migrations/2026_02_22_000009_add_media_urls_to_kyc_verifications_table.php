<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->text('selfie_url')->nullable()->after('failure_reason');
            $table->json('document_photo_urls')->nullable()->after('selfie_url');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->dropColumn(['selfie_url', 'document_photo_urls']);
        });
    }
};
