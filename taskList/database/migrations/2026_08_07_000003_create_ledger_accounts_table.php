<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null merchant_id => a platform/system account (float, fees, expense).
            $table->foreignUuid('merchant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');                 // asset | liability | revenue | expense
            $table->string('kind');                 // merchant_payable | momo_float | fee_revenue | provider_expense
            $table->string('currency', 3);
            $table->string('name');
            $table->timestamps();

            $table->unique(['merchant_id', 'kind', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
