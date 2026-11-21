<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One communications log for every channel (WhatsApp, SMS, e-mail) and every source (panel, signatures, rentals,
 * system e-mails): the WhatsApp messages table becomes clarto_communications, keeping its rows and unique keys
 * (idempotency key; team + provider message id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('clarto_whatsapp_messages', 'clarto_communications');
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->renameColumn('waha_id', 'provider_id');
        });
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->string('channel', 10)->default('whatsapp');      // whatsapp | sms | email
            $table->string('email')->nullable();                      // e-mail recipient/sender
            $table->string('subject')->nullable();
            $table->string('purpose', 20)->default('servicio');       // servicio | cobro | … | marketing (needs consent)
            $table->string('source', 20)->default('panel');           // panel | alquileres | firma | sistema
            $table->string('related_type')->nullable();
            $table->string('related_id', 40)->nullable();
            $table->timestamp('read_at')->nullable();                 // incoming: seen by the team
            $table->index(['team_id', 'party_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'party_id', 'id']);
            $table->dropColumn(['channel', 'email', 'subject', 'purpose', 'source', 'related_type', 'related_id', 'read_at']);
        });
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->renameColumn('provider_id', 'waha_id');
        });
        Schema::rename('clarto_communications', 'clarto_whatsapp_messages');
    }
};
