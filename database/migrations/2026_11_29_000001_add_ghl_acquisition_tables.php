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
        Schema::dropIfExists('clarto_ghl_whatsapp_orders');
        Schema::table('clarto_valuation_settings', fn (Blueprint $table) => $table->dropColumn(['cita_valencia', 'cita_sagunto', 'cita_bellreguard']));
        Schema::table('clarto_valuation_requests', fn (Blueprint $table) => $table->dropColumn(['property_key', 'report_opens', 'ghl_delivery_reported']));
    }
};
