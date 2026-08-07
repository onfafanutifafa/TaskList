<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('method');
            $table->string('path');
            $table->string('request_hash');          // fingerprint of the body; mismatch => 422
            $table->unsignedInteger('response_code')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('status')->default('locked'); // locked | completed
            $table->timestamps();

            $table->unique(['merchant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
