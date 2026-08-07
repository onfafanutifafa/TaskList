<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('country', 2)->default('GH');       // ISO 3166-1 alpha-2
            $table->string('default_currency', 3)->default('GHS');
            $table->string('status')->default('active');       // active | suspended
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret')->nullable();      // whsec_...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
