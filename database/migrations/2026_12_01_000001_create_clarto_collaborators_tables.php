<?php

declare(strict_types=1);

use App\Collaborators\CollaboratorAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colaboradores externos (personas y empresas), su asignación a inmuebles, el seguimiento de cada inmueble
 * (notas internas y novedades compartidas) y los avisos enviados por correo y WhatsApp. Contrato:
 * orquestacion/colaboradores/contrato.md. Crea también el rol «colaborador» (sin permisos) en cada equipo.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->string('kind', 10);                    // persona | empresa
            $table->string('name');                        // nombre de la persona o nombre comercial de la empresa
            $table->string('legal_name')->nullable();      // razón social (empresa)
            $table->string('tax_id', 32)->nullable();
            $table->json('contacts')->nullable();          // [{name, role, email, phone}] personas de contacto
            $table->string('email')->nullable();
            $table->string('whatsapp', 32)->nullable();    // E.164
            $table->string('locale', 5)->default('es');
            $table->foreignId('owner_id')->nullable();     // responsable interno (users.id)
            $table->foreignId('user_id')->nullable()->unique();   // usuario de acceso al panel de colaboradores
            $table->string('status', 12)->default('pendiente');    // pendiente | activo | baja
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('notes')->nullable();             // nota interna: nunca visible para el colaborador
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'status']);
        });

        // Asignación colaborador ↔ inmueble. Activa mientras revoked_at sea null; una retirada se conserva (auditoría).
        Schema::create('clarto_collaborator_properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('collaborator_id');
            $table->foreignId('property_id');
            $table->string('relation', 20);                // aportado | comercializacion | busqueda
            $table->decimal('authorised_price', 14, 2)->nullable();   // precio de comercialización autorizado
            $table->string('availability', 20)->nullable();           // disponible | reservado | no_disponible
            $table->string('commercial_status', 20)->nullable();      // en_comercializacion | con_visitas | con_oferta | reservado | cerrado
            $table->foreignId('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['collaborator_id', 'revoked_at']);
            $table->index(['property_id', 'revoked_at']);
        });

        // Seguimiento del inmueble: nota interna (visibility=interna) o novedad compartida con sus colaboradores.
        Schema::create('clarto_property_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('property_id')->index();
            $table->string('kind', 20);                    // nota | alta | precio | disponibilidad | visita | resultado_visita | oferta | reserva | cierre | otro
            $table->string('visibility', 12);              // interna | compartida
            $table->string('status', 20);                  // registrada (nota) | pendiente_revision | compartida | descartada
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->string('availability', 20)->nullable();
            $table->timestamp('visit_at')->nullable();
            $table->string('visit_status', 12)->nullable();   // programada | realizada | cancelada | aplazada
            $table->text('visit_result')->nullable();
            $table->text('next_steps')->nullable();
            $table->text('conditions')->nullable();        // oferta/reserva/cierre: condiciones que el equipo autoriza a comunicar
            $table->json('attachments')->nullable();       // [{path, name, mime, size}] disco privado
            $table->json('recipients')->nullable();        // ids de colaborador elegidos (null = todos los activos)
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('shared_by')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
        });

        // Avisos a colaboradores (también los usa «Solicitudes»): uno por destinatario y asunto, idempotente por dedupe_key.
        Schema::create('clarto_collaborator_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('collaborator_id')->index();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('dedupe_key')->unique();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->json('whatsapp_blocks')->nullable();   // bloques ordenados
            $table->string('status', 12)->default('pendiente');   // pendiente | enviado | parcial | error | cancelado
            $table->string('email_status', 12)->nullable();       // enviado | error | sin_destino
            $table->string('whatsapp_status', 12)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'collaborator_id']);
        });

        $roles = config('permission.table_names.roles', 'roles');
        foreach (DB::table('teams')->pluck('id') as $teamId) {
            if (! DB::table($roles)->where('team_id', $teamId)->where('name', CollaboratorAccess::ROLE)->where('guard_name', 'web')->exists()) {
                DB::table($roles)->insert(['team_id' => $teamId, 'name' => CollaboratorAccess::ROLE, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        DB::table(config('permission.table_names.roles', 'roles'))->where('name', CollaboratorAccess::ROLE)->delete();
        Schema::dropIfExists('clarto_collaborator_notices');
        Schema::dropIfExists('clarto_property_updates');
        Schema::dropIfExists('clarto_collaborator_properties');
        Schema::dropIfExists('clarto_collaborators');
    }
};
