<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp (WAHA): the company line of each team, its messages, the phone → contact links the team confirmed,
 * and the webhook events already processed. Unique keys make retries harmless: an event id is processed once,
 * a WhatsApp message id is stored once per team, an outgoing idempotency key is sent once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_whatsapp_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->unique();
            $table->string('session', 64)->unique();      // WAHA session name
            $table->string('status', 20)->default('SIN_CREAR');
            $table->string('phone', 20)->nullable();      // connected number, E.164
            $table->string('push_name')->nullable();
            $table->json('templates')->nullable();        // [{name, body}]
            $table->timestamp('status_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->string('direction', 3);               // in | out
            $table->string('phone', 20);                  // the other side, E.164
            $table->foreignId('party_id')->nullable()->index();
            $table->foreignId('instruction_id')->nullable()->index();
            $table->foreignId('user_id')->nullable();
            $table->text('body');
            $table->string('status', 20);                 // pendiente|enviado|entregado|leido|error · recibido|sin_vincular|descartado
            $table->json('candidates')->nullable();       // contacts sharing the phone (resolution queue)
            $table->string('waha_id', 191)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'waha_id']);
            $table->index(['team_id', 'phone']);
        });

        Schema::create('clarto_whatsapp_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('phone', 20);
            $table->foreignId('party_id');
            $table->timestamps();
            $table->unique(['team_id', 'phone']);
        });

        Schema::create('clarto_whatsapp_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 191)->unique();
            $table->string('event', 40);
            $table->string('session', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_whatsapp_events');
        Schema::dropIfExists('clarto_whatsapp_links');
        Schema::dropIfExists('clarto_whatsapp_messages');
        Schema::dropIfExists('clarto_whatsapp_lines');
    }
};
