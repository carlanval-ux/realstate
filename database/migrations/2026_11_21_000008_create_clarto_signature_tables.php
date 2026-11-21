<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Formularios» and signing (docs/clarto/formularios-firma.md):
 * - clarto_form_templates: catalogue of document templates; a used version is immutable (used_at).
 * - clarto_signature_requests: one send for signature, with the exact version sent (template snapshot, values, PDF hash).
 * - clarto_signature_signers: one row per signer and state.
 * - clarto_signature_events: provider webhooks, unique per event key (a repeated webhook is a no-op).
 * - clarto_signature_documents: signed PDF and audit log stored privately, unique per request/kind/name.
 * - clarto_sms_messages: signing links and reminders sent by SMS.
 * Seeds one FICTITIOUS template for tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_form_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);                       // logical key, same for every version
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->string('status', 20)->default('borrador');   // borrador | activo | retirado
            $table->string('signature_level', 20)->default('simple'); // simple | avanzada
            $table->string('source', 20)->default('docuseal');  // docuseal | pdf
            $table->json('docuseal_template_ids')->nullable(); // {"sandbox": 123, "production": 456}
            $table->string('pdf_path')->nullable();          // private disk, source = pdf
            $table->string('pdf_sha256', 64)->nullable();
            $table->longText('body')->nullable();            // text with {{campo}} for the preview
            $table->json('roles');                           // [{key, label, docuseal_role, from}]
            $table->json('fields');                          // [{key, label, source, required, docuseal_field, role}]
            $table->text('notes')->nullable();
            $table->boolean('fictitious')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique(['key', 'version']);
        });

        Schema::create('clarto_signature_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('form_template_id')->nullable()->index();
            $table->string('template_key', 80)->nullable();
            $table->unsignedInteger('template_version')->nullable();
            $table->json('template_snapshot')->nullable();   // the template definition exactly as sent
            $table->string('title');
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->unsignedBigInteger('instruction_id')->nullable()->index();
            $table->json('values')->nullable();
            $table->string('document_path')->nullable();     // private disk: PDF sent (or preview of a DocuSeal template)
            $table->string('document_sha256', 64)->nullable();
            $table->string('signature_level', 20)->default('simple');
            $table->string('driver', 20);                    // docuseal | simulado
            $table->string('environment', 20)->nullable();   // sandbox | production
            $table->string('provider_submission_id', 40)->nullable();
            $table->string('status', 20)->default('borrador'); // borrador | enviada | en_curso | completada | rechazada | caducada | anulada | error
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['driver', 'environment', 'provider_submission_id'], 'clarto_sigreq_provider_unique');
        });

        Schema::create('clarto_signature_signers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_request_id')->index();
            $table->string('role', 60);
            $table->string('provider_role', 60)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('external_id', 64)->unique();     // our idempotency key, sent to the provider
            $table->string('provider_submitter_id', 40)->nullable();
            $table->text('sign_url')->nullable();            // encrypted: it opens the document
            $table->boolean('require_phone_2fa')->default(false);
            $table->string('status', 20)->default('pendiente'); // pendiente | enviado | visto | firmado | rechazado
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('otp_verified_at')->nullable(); // only when the provider reports it
            $table->string('otp_channel', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_signature_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_key', 64)->unique();
            $table->string('event_type', 60);
            $table->foreignId('signature_request_id')->nullable()->index();
            $table->json('payload');
            $table->string('result', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_signature_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_request_id')->index();
            $table->string('kind', 20);                      // firmado | auditoria
            $table->string('name');
            $table->string('path');                          // private disk
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->unique(['signature_request_id', 'kind', 'name'], 'clarto_sigdoc_unique');
        });

        Schema::create('clarto_sms_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signature_request_id')->nullable()->index();
            $table->foreignId('signature_signer_id')->nullable()->index();
            $table->string('kind', 20);                      // enlace | recordatorio
            $table->string('to', 20);
            $table->string('text', 320);                     // the link is masked here
            $table->string('driver', 20);
            $table->string('status', 20);                    // aceptado | rechazado | bloqueado | error
            $table->string('provider_message_id', 40)->nullable();
            $table->string('error')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();
        });

        DB::table('clarto_form_templates')->insert([
            'key' => 'prueba-hoja-de-encargo',
            'version' => 1,
            'name' => 'PRUEBA · Hoja de encargo ficticia',
            'status' => 'activo',
            'signature_level' => 'simple',
            'source' => 'docuseal',
            'docuseal_template_ids' => json_encode(['sandbox' => null, 'production' => null]),
            'body' => "DOCUMENTO FICTICIO DE PRUEBA · SIN VALOR JURÍDICO\n\n"
                ."En {{municipio}}, a {{fecha}}.\n\n"
                ."{{propietario_nombre}}, con documento {{propietario_documento}}, propietario/a del inmueble situado en {{inmueble_direccion}} "
                ."(referencia {{inmueble_referencia}}, {{inmueble_superficie}} m²), encarga a CLARTO la {{operacion}} del inmueble por un precio orientativo de {{precio}} €.\n\n"
                ."Encargo: {{encargo_asunto}}.\n\n"
                ."Firmado: el/la propietario/a y CLARTO.",
            'roles' => json_encode([
                ['key' => 'propietario', 'label' => 'Propietario/a', 'docuseal_role' => 'Propietario', 'from' => 'owner'],
                ['key' => 'agencia', 'label' => 'CLARTO', 'docuseal_role' => 'Agencia', 'from' => 'staff'],
            ]),
            'fields' => json_encode([
                ['key' => 'propietario_nombre', 'label' => 'Nombre del propietario', 'source' => 'propietario.name', 'required' => true, 'docuseal_field' => 'Nombre propietario', 'role' => 'propietario'],
                ['key' => 'propietario_documento', 'label' => 'DNI/NIE del propietario', 'source' => 'propietario.metadata.documento', 'required' => true, 'docuseal_field' => 'DNI propietario', 'role' => 'propietario'],
                ['key' => 'inmueble_direccion', 'label' => 'Dirección del inmueble', 'source' => 'inmueble.address', 'required' => true, 'docuseal_field' => 'Direccion', 'role' => 'propietario'],
                ['key' => 'inmueble_referencia', 'label' => 'Referencia del inmueble', 'source' => 'inmueble.referencia', 'required' => true, 'docuseal_field' => 'Referencia', 'role' => 'propietario'],
                ['key' => 'inmueble_superficie', 'label' => 'Superficie (m²)', 'source' => 'inmueble.area_sqft', 'required' => false, 'docuseal_field' => 'Superficie', 'role' => 'propietario'],
                ['key' => 'municipio', 'label' => 'Municipio', 'source' => 'inmueble.municipio', 'required' => false, 'docuseal_field' => 'Municipio', 'role' => 'propietario'],
                ['key' => 'operacion', 'label' => 'Operación', 'source' => 'inmueble.operacion', 'required' => true, 'docuseal_field' => 'Operacion', 'role' => 'propietario'],
                ['key' => 'precio', 'label' => 'Precio orientativo (€)', 'source' => 'inmueble.price', 'required' => true, 'docuseal_field' => 'Precio', 'role' => 'propietario'],
                ['key' => 'encargo_asunto', 'label' => 'Encargo', 'source' => 'encargo.subject', 'required' => false, 'docuseal_field' => 'Encargo', 'role' => 'propietario'],
                ['key' => 'fecha', 'label' => 'Fecha', 'source' => 'hoy', 'required' => true, 'docuseal_field' => 'Fecha', 'role' => 'propietario'],
            ]),
            'notes' => 'Plantilla ficticia para pruebas. Los documentos definitivos se añadirán cuando estén aprobados.',
            'fictitious' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['clarto_sms_messages', 'clarto_signature_documents', 'clarto_signature_events', 'clarto_signature_signers', 'clarto_signature_requests', 'clarto_form_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
