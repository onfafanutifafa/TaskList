<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('from_currency', 8);
            $table->string('to_currency', 8);
            $table->unsignedBigInteger('from_amount_minor');   // debited from source wallet
            $table->unsignedBigInteger('to_amount_minor');     // credited to dest wallet (net of spread)
            $table->unsignedBigInteger('spread_minor')->default(0); // FX revenue, in dest currency
            $table->string('rate');                            // units of TO per 1 FROM, at execution
            $table->unsignedInteger('spread_bps');
            $table->string('reference')->nullable();
            $table->string('status')->default('completed');
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
