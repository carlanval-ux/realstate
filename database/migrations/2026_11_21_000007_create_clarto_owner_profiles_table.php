<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 360 profile (Propietarios): one row per owner Party (1:1), with the Spanish identity, fiscal and banking
 * data the module's Party does not hold. Name, e-mail and phone stay on the Party.
 *
 * clarto_ghl_handoffs gains the "pendiente de vinculación" queue: when the GHL contact's identity is ambiguous,
 * the candidate owners are kept for a person to choose, and who linked it and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_owner_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('party_id')->unique();
            $table->string('person_type', 10)->default('fisica');       // fisica | juridica (CRM001 party.kind)
            $table->string('first_name', 200)->nullable();
            $table->string('last_name', 200)->nullable();              // primer apellido
            $table->string('second_last_name', 200)->nullable();       // segundo apellido
            $table->date('birth_date')->nullable();
            $table->string('nationality', 2)->nullable();              // ISO 3166-1 alpha-2
            $table->string('legal_name', 200)->nullable();             // razón social
            $table->string('trading_name', 200)->nullable();           // nombre comercial
            $table->string('tax_id_type', 10)->nullable();             // nif | nie | cif | passport | other
            $table->string('tax_id', 40)->nullable();                  // normalised (no spaces . - /, upper case)
            $table->string('representative_name', 200)->nullable();
            $table->string('representative_tax_id', 40)->nullable();
            $table->string('phone_secondary', 50)->nullable();
            $table->string('contact_window', 100)->nullable();         // e.g. "tardes a partir de las 17 h"
            $table->string('fiscal_address')->nullable();
            $table->string('fiscal_postal_code', 10)->nullable();
            $table->string('fiscal_city')->nullable();
            $table->string('fiscal_province')->nullable();
            $table->string('fiscal_country', 2)->nullable()->default('ES');
            $table->boolean('postal_same_as_fiscal')->default(true);
            $table->string('postal_address')->nullable();
            $table->string('postal_postal_code', 10)->nullable();
            $table->string('postal_city')->nullable();
            $table->string('postal_province')->nullable();
            $table->string('postal_country', 2)->nullable();
            $table->string('preferred_channel', 20)->nullable();       // email | phone | whatsapp | sms | post
            $table->string('preferred_language', 5)->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('privacy_consent_version', 20)->nullable();
            $table->timestamp('marketing_consent_at')->nullable();
            $table->string('marketing_consent_version', 20)->nullable();
            $table->string('marketing_consent_channel', 10)->nullable(); // email | sms | phone | post | any
            $table->string('consent_source', 40)->nullable();
            $table->string('consent_evidence', 2000)->nullable();
            $table->string('payment_method', 20)->nullable();          // direct_debit | bank_transfer
            $table->text('iban')->nullable();                          // encrypted
            $table->string('bic', 11)->nullable();
            $table->string('iban_holder', 200)->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->string('source', 20)->default('panel');            // ghl | panel
            $table->timestamps();
            $table->unique(['team_id', 'tax_id']);
        });

        Schema::table('clarto_ghl_handoffs', function (Blueprint $table): void {
            $table->json('candidate_party_ids')->nullable()->after('instruction_id');
            $table->json('contact')->nullable()->after('candidate_party_ids');
            $table->foreignId('linked_by')->nullable()->after('contact');
            $table->timestamp('linked_at')->nullable()->after('linked_by');
        });
    }

    public function down(): void
    {
        Schema::table('clarto_ghl_handoffs', function (Blueprint $table): void {
            $table->dropColumn(['candidate_party_ids', 'contact', 'linked_by', 'linked_at']);
        });
        Schema::dropIfExists('clarto_owner_profiles');
    }
};
