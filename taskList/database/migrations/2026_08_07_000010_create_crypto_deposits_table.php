<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On-chain detail for a crypto-deposit transaction. One row per deposit
        // intent; the watcher fills in what it observes on-chain.
        Schema::create('crypto_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('asset');                 // USDT | USDC
            $table->string('chain');                 // tron | ethereum | base
            $table->string('address');               // where the payer sends funds
            $table->string('memo')->nullable();      // tag to disambiguate a shared address
            $table->unsignedBigInteger('amount_expected_minor');
            $table->unsignedBigInteger('amount_received_minor')->default(0);
            $table->unsignedInteger('confirmations')->default(0);
            $table->unsignedInteger('required_confirmations');
            $table->string('tx_hash')->nullable()->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['address', 'chain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crypto_deposits');
    }
};
