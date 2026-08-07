<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Entries are never updated or deleted; corrections are new
        // balancing journals. Each journal_id groups a set of legs that sum to zero.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('journal_id')->index();
            $table->foreignUuid('account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction');            // debit | credit
            $table->unsignedBigInteger('amount_minor'); // always positive
            $table->string('currency', 3);
            $table->string('narration')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
