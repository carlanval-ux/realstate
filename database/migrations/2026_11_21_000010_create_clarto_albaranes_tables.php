<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Albaranes de servicio: services done for a client (owner), sent to the billing program as pending invoicing.
 * The billing program owns numbering and tax treatment of the invoice; we keep its ids and the invoice it issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_albaranes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('number', 30)->unique();                     // ALB-2026-00001 (ours, not an invoice number)
            $table->foreignId('party_id')->constrained('real_estate_parties');
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->unsignedBigInteger('instruction_id')->nullable()->index();
            $table->date('service_date');
            // Client data as printed on the albarán (prefilled from the owner, editable while it is a draft).
            $table->string('client_name');
            $table->string('client_tax_id', 20)->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_phone', 50)->nullable();
            $table->string('client_address')->nullable();
            $table->text('notes')->nullable();
            // borrador | pendiente_envio | enviado | error | facturado
            $table->string('status', 20)->default('borrador')->index();
            // no_requerida | pendiente | firmada
            $table->string('conformity', 20)->default('no_requerida');
            $table->unsignedBigInteger('signature_request_id')->nullable();
            $table->decimal('total', 12, 2)->default(0);                  // sum of lines, before taxes
            $table->string('external_id', 80)->nullable()->index();      // albarán id in the billing program
            $table->string('idempotency_key', 64)->unique();             // sent on every attempt: a retry never duplicates
            $table->string('driver', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_response')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('invoice_number', 40)->nullable();
            $table->date('invoice_date')->nullable();
            $table->string('invoice_external_id', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('clarto_albaran_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('albaran_id')->constrained('clarto_albaranes')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('service_code', 40)->nullable();              // catalogue code; null = free line
            $table->string('description', 500);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        // One billing-program client per owner: found or created once, reused by every albarán.
        Schema::create('clarto_billing_clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->unique()->constrained('real_estate_parties')->cascadeOnDelete();
            $table->string('external_id', 80);
            $table->string('driver', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_billing_clients');
        Schema::dropIfExists('clarto_albaran_lines');
        Schema::dropIfExists('clarto_albaranes');
    }
};
