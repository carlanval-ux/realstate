<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liberu record ↔ TARMIO task. One row per (source record, kind of action), unique: a retried or repeated event
 * finds the row and updates the same TARMIO task instead of creating another one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_tarmio_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 40);                       // alta_propietario | firma | entrada | salida | averia | albaran ...
            $table->string('title', 300);
            $table->string('assignee_email')->nullable();
            $table->date('due_on')->nullable();
            $table->string('url', 500);
            $table->string('tarmio_task_id', 64)->nullable();
            $table->string('tarmio_ref', 32)->nullable();     // TSK-00042
            $table->string('synced_hash', 40)->nullable();    // what TARMIO has; equal to the current data = nothing to send
            $table->string('status', 20)->default('pendiente'); // pendiente | sincronizada | error
            $table->string('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_tarmio_tasks');
    }
};
