<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLARTO rentals (Alquiler tradicional, por habitaciones y vacacional) on one shared occupancy base.
 * See docs/clarto/alquileres.md. Every row carries team_id; nothing is hard-deleted by the app (history is kept
 * when a module is switched off).
 */
return new class() extends Migration
{
    public function up(): void
    {
        // Rentable unit: the whole dwelling (parent_id null) or one of its rooms (parent_id = the dwelling).
        Schema::create('clarto_rent_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('property_unit_id')->nullable(); // optional link to Liberu real_estate_property_units
            $table->string('kind', 20); // vivienda | habitacion
            $table->string('name', 120);
            $table->unsignedSmallInteger('max_occupants')->default(1);
            $table->decimal('area_m2', 8, 2)->nullable();
            $table->json('common_areas')->nullable();
            $table->decimal('reference_rent', 12, 2)->nullable(); // dwelling: monthly rent of the whole (cap for its rooms, LAU art. 17.9)
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(0); // written first inside every booking transaction
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['property_id', 'name']);
        });

        // Contract (tradicional, habitaciones), booking (vacacional) or calendar block. Dates are [start, end): the
        // exit day is free for the next entry.
        Schema::create('clarto_rent_occupancies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('unit_id')->index();
            $table->string('module', 20); // tradicional | habitaciones | vacacional
            $table->string('kind', 20)->default('contrato'); // contrato | reserva | bloqueo
            $table->string('purpose', 20)->nullable(); // habitual | temporal | turistica
            $table->text('temporal_cause')->nullable();
            $table->string('status', 20)->default('borrador');
            $table->date('planned_start');
            $table->date('planned_end');
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->unsignedSmallInteger('occupants')->default(1);
            $table->string('channel', 40)->nullable();
            $table->string('channel_ref', 120)->nullable();
            $table->decimal('rent_amount', 12, 2)->nullable();
            $table->string('rent_period', 10)->nullable(); // mes | noche | estancia
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->json('utilities')->nullable();
            $table->boolean('landlord_is_company')->default(false);
            $table->unsignedSmallInteger('renewals')->default(0);
            $table->unsignedBigInteger('previous_occupancy_id')->nullable()->index(); // room change / renewal chain
            $table->string('end_reason', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['property_id', 'status', 'planned_start']);
        });

        // People and their role in one occupancy. Identity and solvency are encrypted and permission-gated.
        Schema::create('clarto_rent_people', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('occupancy_id')->index();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->string('role', 20); // titular | firmante | arrendatario | ocupante | avalista | propietario | viajero
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('identity')->nullable();
            $table->text('solvency')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Append-only history of each occupancy (status changes, renewals, notices, keys, inventory, check-in/out…).
        Schema::create('clarto_rent_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('occupancy_id')->index();
            $table->string('type', 40);
            $table->date('occurred_on');
            $table->json('data')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Money owed or paid: rent, utilities, nightly rates, cleaning, CLARTO services, owner settlement…
        // Deposits and guarantees are NOT here (clarto_rent_guarantees): they are not income.
        Schema::create('clarto_rent_charges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('occupancy_id')->index();
            $table->string('concept', 30);
            $table->string('period', 20)->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('payer', 20)->default('inquilino'); // inquilino | propietario | viajero | canal | clarto
            $table->date('due_on');
            $table->date('paid_on')->nullable();
            $table->string('status', 20)->default('pendiente'); // pendiente | cobrado | impagado | devuelto | anulado
            $table->string('albaran_ref', 60)->nullable();
            $table->json('data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Fianza legal, garantía adicional, aval and impago insurance: separate concepts, never income.
        Schema::create('clarto_rent_guarantees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('occupancy_id')->index();
            $table->string('type', 30); // fianza | garantia_adicional | aval | seguro_impago
            $table->decimal('amount', 12, 2);
            $table->string('provider')->nullable(); // bank, insurer or guarantor name
            $table->string('status', 20)->default('pendiente'); // pendiente | recibida | depositada | devuelta | devuelta_parcial | ejecutada
            $table->date('received_on')->nullable();
            $table->string('deposit_authority', 120)->nullable();
            $table->string('deposit_receipt', 120)->nullable(); // resguardo de depósito
            $table->date('deposited_on')->nullable();
            $table->decimal('returned_amount', 12, 2)->nullable();
            $table->date('returned_on')->nullable();
            $table->json('deductions')->nullable(); // [{concept, amount, justification, incident_id}]
            $table->timestamps();
            $table->softDeletes();
        });

        // Breakdowns, maintenance and cleaning jobs.
        Schema::create('clarto_rent_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('occupancy_id')->nullable()->index();
            $table->string('kind', 20)->default('averia'); // averia | mantenimiento | limpieza | otro
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal'); // baja | normal | alta | urgente
            $table->json('photos')->nullable();
            $table->boolean('access_authorized')->default(false);
            $table->string('access_notes')->nullable();
            $table->unsignedBigInteger('provider_party_id')->nullable();
            $table->string('provider_name')->nullable();
            $table->decimal('quote_amount', 12, 2)->nullable();
            $table->decimal('final_amount', 12, 2)->nullable();
            $table->string('cost_bearer', 20)->default('pendiente'); // propietario | inquilino | clarto | seguro | pendiente
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->string('status', 20)->default('abierta'); // abierta | presupuestada | en_curso | resuelta | cerrada
            $table->date('reported_on');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Contracts, annexes, inventories and records prepared for «Formularios» and signature.
        Schema::create('clarto_rent_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('occupancy_id')->index();
            $table->string('kind', 30); // contrato | anexo | inventario | entrega_llaves | preaviso | acta_salida
            $table->string('template', 80);
            $table->string('title');
            $table->text('values')->nullable(); // encrypted: holds identity data
            $table->string('status', 20)->default('borrador'); // borrador | preparado | enviado_firma | firmado | anulado
            $table->string('external_ref', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Holiday rental: nightly rate per season.
        Schema::create('clarto_rent_seasons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('unit_id')->index();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on'); // inclusive
            $table->decimal('nightly_rate', 10, 2);
            $table->unsignedSmallInteger('min_nights')->default(1);
            $table->timestamps();
        });

        // Licences and registration numbers (VUT Comunitat Valenciana, Registro Único, SES establishment…).
        Schema::create('clarto_rent_licences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('property_id')->index();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('type', 30);
            $table->string('number', 120)->nullable();
            $table->string('status', 20)->default('en_tramite'); // en_tramite | vigente | suspendida | caducada | baja
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('authority', 120)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Per-company channels, templates and rules.
        Schema::create('clarto_rent_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->unique();
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'licences', 'seasons', 'documents', 'incidents', 'guarantees', 'charges', 'events', 'people', 'occupancies', 'units'] as $name) {
            Schema::dropIfExists('clarto_rent_'.$name);
        }
    }
};
