<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw audit of every inbound provider callback, kept even if unmatched.
        Schema::create('provider_callbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');              // mtn_momo
            $table->string('product');               // collection | disbursement
            $table->string('reference')->index();    // our X-Reference-Id
            $table->json('payload');
            $table->boolean('signature_valid')->default(true);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_callbacks');
    }
};
