<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (merchant, currency). It is the lock anchor for spend
        // decisions: a spend SELECT ... FOR UPDATEs this row, so concurrent
        // payouts/conversions serialise and cannot oversell the same balance.
        // `reserved_minor` is the funds held by in-flight (not-yet-settled) debits.
        Schema::create('balance_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 8);
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->timestamps();

            $table->unique(['merchant_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_reservations');
    }
};
