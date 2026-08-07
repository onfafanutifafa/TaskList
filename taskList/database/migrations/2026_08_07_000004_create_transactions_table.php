<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();          // also the X-Reference-Id sent to MTN
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('type');                 // collection | payout
            $table->string('status')->index();      // pending | processing | succeeded | failed
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->string('currency', 3);
            $table->string('provider');             // mtn_momo
            $table->string('network');              // mtn
            $table->string('msisdn');               // payer (collection) / recipient (payout)
            $table->string('reference');            // merchant-supplied reference
            $table->string('provider_reference')->nullable()->index(); // financialTransactionId
            $table->string('narration')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
