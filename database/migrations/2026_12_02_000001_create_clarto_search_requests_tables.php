<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Solicitudes de inmuebles» (docs/clarto/solicitudes.md): searches the team sends to its external collaborators,
 * who they went to, the collaborators' answers and proposals, replies waiting to be linked, and the audit trail.
 * Collaborators and the copy of every message sent live in the «Colaboradores» module (clarto_collaborators,
 * clarto_collaborator_notices; collaborator_id without FK: that module owns its tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_search_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('reference', 20);                            // SOL-2026-000123, correlative per team
            $table->string('status', 20)->default('borrador')->index(); // borrador|abierta|en_evaluacion|cubierta|cerrada|caducada
            $table->unsignedInteger('version')->default(1);             // +1 on every update sent to collaborators
            $table->string('title', 160);
            $table->string('operation', 20);
            $table->string('property_type', 30);
            $table->string('municipality', 120)->nullable();
            $table->string('zones', 500)->nullable();
            $table->string('address', 250)->nullable();
            $table->unsignedInteger('surface_min')->nullable();
            $table->unsignedInteger('surface_max')->nullable();
            $table->unsignedBigInteger('budget_min')->nullable();
            $table->unsignedBigInteger('budget_max')->nullable();
            $table->unsignedTinyInteger('bedrooms_min')->nullable();
            $table->text('features')->nullable();
            $table->text('must_have')->nullable();
            $table->text('preferences')->nullable();
            $table->date('needed_by')->nullable();
            $table->date('respond_by');                                // deadline (company time zone), required
            $table->text('description')->nullable();
            $table->json('example_links')->nullable();
            $table->json('attachments')->nullable();                   // paths on the private disk
            $table->json('attachment_names')->nullable();              // path => original name
            $table->unsignedBigInteger('owner_id')->nullable()->index(); // internal person in charge (users.id)
            $table->text('outcome')->nullable();
            $table->string('close_reason', 120)->nullable();            // e.g. «Plazo agotado»
            $table->unsignedBigInteger('property_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'reference']);
        });

        // Correlative reference per company and year, taken with an atomic increment (no gaps, no duplicates).
        Schema::create('clarto_search_request_counters', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last')->default(0);
            $table->primary(['team_id', 'year']);
        });

        // Per company: default deadline, reminders, notice to the owner, time zone.
        Schema::create('clarto_search_request_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->primary();
            $table->unsignedSmallInteger('default_days')->default(21);
            $table->json('reminder_days')->nullable();                 // days before the deadline, e.g. [7, 2]
            $table->unsignedSmallInteger('owner_notice_days')->default(3);
            $table->string('timezone', 40)->default('Europe/Madrid');
            $table->timestamps();
        });

        Schema::create('clarto_search_request_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_request_id')->constrained('clarto_search_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('collaborator_id')->index();
            $table->string('status', 20)->default('pendiente');         // pendiente|enviada|respondida|retirada
            $table->string('last_response', 20)->nullable();            // kind of the collaborator's last answer
            $table->unsignedInteger('sent_version')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->timestamps();
            $table->unique(['search_request_id', 'collaborator_id']);
        });

        Schema::create('clarto_search_request_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_request_id')->constrained('clarto_search_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('team_id')->index();               // collaborator scope filters by team
            $table->foreignId('recipient_id')->constrained('clarto_search_request_recipients')->cascadeOnDelete();
            $table->unsignedBigInteger('collaborator_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();   // completes an earlier proposal (versions kept)
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('kind', 20)->nullable();                     // encaja|puede_buscar|aclaraciones|no_puede (null: reply to classify)
            $table->string('channel', 10)->default('panel');            // panel|email|whatsapp
            $table->text('description')->nullable();
            $table->string('location', 250)->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->text('features')->nullable();
            $table->json('links')->nullable();
            $table->json('photos')->nullable();
            $table->string('estimated_time', 120)->nullable();
            $table->string('status', 20)->default('nueva');             // nueva|en_estudio|seleccionada|descartada
            $table->text('team_notes')->nullable();
            $table->unsignedBigInteger('communication_id')->nullable()->index();
            $table->unsignedBigInteger('property_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();          // who wrote it (collaborator's user or staff linking a reply)
            $table->timestamps();
        });

        // Private answer links (https://panel…/s/{code}): one per message sent to a recipient, only the SHA-256 kept.
        // Valid while the request accepts answers and the link is not revoked.
        Schema::create('clarto_search_request_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('clarto_search_request_recipients')->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();
        });

        // Replies by e-mail or WhatsApp whose request could not be identified with certainty.
        Schema::create('clarto_search_request_inbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('communication_id')->unique();
            $table->unsignedBigInteger('collaborator_id')->nullable();
            $table->json('candidates')->nullable();                     // request ids sent to that collaborator
            $table->string('reason', 120)->nullable();
            $table->string('status', 20)->default('pendiente');         // pendiente|vinculado|descartado
            $table->unsignedBigInteger('response_id')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_search_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('search_request_id')->constrained('clarto_search_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 40);
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // WhatsApp replies that quote one of our messages: the quoted message id (WAHA replyTo).
        Schema::table('clarto_communications', function (Blueprint $table): void {
            $table->string('reply_to', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clarto_communications', fn (Blueprint $table) => $table->dropColumn('reply_to'));
        foreach (['events', 'inbox', 'links', 'responses', 'recipients', 'settings', 'counters'] as $t) {
            Schema::dropIfExists('clarto_search_request_'.$t);
        }
        Schema::dropIfExists('clarto_search_requests');
    }
};
