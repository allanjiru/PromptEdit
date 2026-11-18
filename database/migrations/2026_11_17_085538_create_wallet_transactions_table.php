<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            $table->foreignId('generation_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('idempotency_key')->unique();

            $table->bigInteger('available_delta_units')->default(0);
            $table->bigInteger('reserved_delta_units')->default(0);

            $table->bigInteger('available_after_units');
            $table->bigInteger('reserved_after_units');

            $table->string('description');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['generation_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
