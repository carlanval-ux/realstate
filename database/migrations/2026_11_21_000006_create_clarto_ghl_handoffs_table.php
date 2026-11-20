<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Won GHL opportunities handed over to Liberu. One row per GHL opportunity (unique): a retried or duplicated
 * webhook finds it and never opens a second file. Keeps the GHL ids next to what Liberu created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_ghl_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_id', 64)->unique();
            $table->string('contact_id', 64)->index();
            $table->string('location_id', 64)->nullable();
            $table->string('pipeline_id', 64)->nullable();
            $table->string('status', 20);                 // recibido | ignorado | procesado | error
            $table->string('reason')->nullable();
            $table->foreignId('party_id')->nullable();
            $table->foreignId('property_id')->nullable();
            $table->foreignId('instruction_id')->nullable();
            $table->json('opportunity')->nullable();
            $table->unsignedInteger('deliveries')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_ghl_handoffs');
    }
};
