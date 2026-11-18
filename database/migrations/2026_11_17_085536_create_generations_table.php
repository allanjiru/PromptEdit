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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->uuid('request_key')->unique();
            $table->string('provider_job_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('image_size', 40);
            $table->string('quality', 40);

            $table->json('reference_image_urls')->nullable();
            $table->json('result_image_urls')->nullable();

            $table->string('status', 30)->default('queued')->index();

            $table->bigInteger('estimated_charge_units')->nullable();
            $table->bigInteger('provider_cost_units')->nullable();
            $table->bigInteger('customer_charge_units')->nullable();

            $table->text('error_message')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
