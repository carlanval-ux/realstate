<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files kept on a client (Party) or a property record: DNI/NIE, deeds, contracts, nota simple, energy certificate…
 * A host table rather than a `party_id` on the module's real_estate_media_documents: those rows are public-disk
 * media served by the module REST API, its Livewire list and the global resource; these are private files with
 * their own permission rules (identity / solvency) and must never reach those surfaces. Nothing existing is moved.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->string('category', 40);
            $table->string('title');
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_documents');
    }
};
