<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);           // USD | GBP | EUR
            $table->string('provider');              // baas
            $table->string('status')->default('active');
            $table->string('rail')->nullable();      // ach | faster_payments | sepa
            $table->string('account_name');
            $table->string('account_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('routing_number')->nullable(); // US ABA
            $table->string('sort_code')->nullable();      // UK
            $table->string('iban')->nullable();           // EU/UK
            $table->string('swift_bic')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();

            // One virtual account per currency per merchant.
            $table->unique(['merchant_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_accounts');
    }
};
