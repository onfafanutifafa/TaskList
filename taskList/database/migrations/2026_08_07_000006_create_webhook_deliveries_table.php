<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');                 // transaction.succeeded | transaction.failed
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | delivered | failed
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('last_response_code')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
