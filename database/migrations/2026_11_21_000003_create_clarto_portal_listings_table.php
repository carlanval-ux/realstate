<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per property and portal (pisos.com first): the portal's id for the listing and the state of the
 * last synchronisation, mirroring the sync records of the official Rightmove/Zoopla connectors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_portal_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained('real_estate_properties')->cascadeOnDelete();
            $table->string('portal', 30);
            $table->string('external_id', 60)->nullable();
            // no_enviado | enviado | aceptado | publicado | rechazado | retirado | error
            $table->string('status', 20)->default('no_enviado');
            $table->string('last_action', 20)->nullable();
            $table->string('driver', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->json('last_response')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'portal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_portal_listings');
    }
};
