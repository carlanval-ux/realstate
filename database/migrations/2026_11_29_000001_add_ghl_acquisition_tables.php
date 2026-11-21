<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GoHighLevel acquisition circuit (docs/clarto/captacion-ghl.md): property key of each valuation request (one GHL
 * opportunity per property), report opens, what was last reported to GHL about the delivery, the calendar links per
 * office, WhatsApp messages GHL asks Liberu to send, and numbers that asked not to receive WhatsApp («BAJA»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_valuation_requests', function (Blueprint $table): void {
            $table->string('property_key', 80)->nullable()->index();
            $table->unsignedInteger('report_opens')->default(0);
            $table->string('ghl_delivery_reported', 64)->nullable();
        });

        Schema::table('clarto_valuation_settings', function (Blueprint $table): void {
            $table->string('cita_valencia')->nullable();
            $table->string('cita_sagunto')->nullable();
            $table->string('cita_bellreguard')->nullable();
        });

        Schema::create('clarto_ghl_whatsapp_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 191)->unique();   // idempotency key sent by the GHL workflow
            $table->string('contact_id', 64)->index();
            $table->string('tipo', 10);               // w2|a3|a4|a5
            $table->text('texto');
            $table->timestamp('caduca')->nullable();
            $table->string('estado', 20);             // enviado|en_cola|rechazado|error
            $table->string('motivo')->nullable();
            $table->string('phone', 32)->nullable();
            $table->unsignedBigInteger('communication_id')->nullable();
            $table->unsignedInteger('intentos')->default(0);
            $table->timestamps();
        });

        // WhatsApp nurture sent by Liberu through WAHA (B.3 v2 §5, L-18…L-21): one plan per GHL contact and cycle,
        // one slot per planned message.
        Schema::create('clarto_whatsapp_nurture_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('contact_id', 64)->index();   // GHL contact
            $table->unsignedBigInteger('valuation_request_id')->nullable();
            $table->string('phone', 32)->index();
            $table->string('rama', 10);                   // activa|templada|fria
            $table->string('asesor_nombre', 80)->nullable();
            $table->string('estado', 12)->default('activo')->index();   // activo|cancelado|terminado
            $table->string('motivo')->nullable();
            $table->json('eventos')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_whatsapp_nurture_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('clarto_whatsapp_nurture_plans')->cascadeOnDelete();
            $table->string('codigo', 10);                 // wa1…wa4, wa_t1, wa_t2, wa_f1
            $table->string('evento', 4);                  // the e-mail that scheduled it (c2…c5)
            $table->timestamp('send_after');
            $table->timestamp('window_end');
            $table->string('estado', 12)->default('programado')->index();   // programado|enviado|cancelado|omitido
            $table->string('motivo')->nullable();
            $table->unsignedBigInteger('communication_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'codigo']);
        });

        Schema::create('clarto_whatsapp_optouts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('phone', 32);
            $table->string('source', 40)->default('whatsapp');
            $table->timestamp('created_at')->nullable();
            $table->unique(['team_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_whatsapp_optouts');
        Schema::dropIfExists('clarto_whatsapp_nurture_slots');
        Schema::dropIfExists('clarto_whatsapp_nurture_plans');
        Schema::dropIfExists('clarto_ghl_whatsapp_orders');
        Schema::table('clarto_valuation_settings', fn (Blueprint $table) => $table->dropColumn(['cita_valencia', 'cita_sagunto', 'cita_bellreguard']));
        Schema::table('clarto_valuation_requests', fn (Blueprint $table) => $table->dropColumn(['property_key', 'report_opens', 'ghl_delivery_reported']));
    }
};
