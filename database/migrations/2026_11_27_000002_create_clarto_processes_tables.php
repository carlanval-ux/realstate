<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Procesos y conocimiento»: the process (not versioned), its immutable versions, executing positions, audience,
 * private attachments, append-only history, personal reading state, the bridge to «Solicitud de mejoras», per-company
 * settings and the process map. Contract: orquestacion/procesos/modelo-datos.md §3 (shared with the assistant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->string('code', 12);
            $table->foreignId('area_id')->constrained('clarto_areas');
            $table->string('category', 20);       // comercial | operativo | administrativo | financiero | soporte
            $table->string('business_line', 20);  // inmobiliaria | alquileres | consultoria | gestoria | transversal
            $table->string('kind', 10);           // breve | completo
            $table->string('status', 12)->index(); // borrador | publicado | archivado
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('owner_user_id')->nullable();
            $table->foreignId('approver_user_id')->nullable();
            $table->string('visibility', 12)->default('empresa'); // empresa | restringido
            $table->boolean('ai_excluded')->default(false);
            $table->unsignedBigInteger('map_entry_id')->nullable();
            $table->string('template_key', 40)->nullable();
            $table->date('next_review_on')->nullable()->index();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->text('archive_reason')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'code']);
        });

        Schema::create('clarto_process_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->unsignedInteger('number')->nullable();
            $table->string('status', 12)->index(); // borrador | en_revision | publicada | sustituida | archivada
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->string('title', 160);
            $table->string('summary', 300)->nullable();
            $table->text('objective')->nullable();
            $table->text('expected_result')->nullable();
            $table->text('trigger')->nullable();
            $table->text('requirements')->nullable();
            $table->text('required_data')->nullable();
            $table->json('steps')->nullable();
            $table->json('decisions')->nullable();
            $table->boolean('no_decisions')->default(false);
            $table->json('exceptions')->nullable();
            $table->text('escalation')->nullable();
            $table->json('tools')->nullable();
            $table->text('evidences')->nullable();
            $table->text('deadlines')->nullable();
            $table->json('kpis')->nullable();
            $table->json('related')->nullable();
            $table->json('blocks')->nullable();
            $table->text('diagram_mermaid')->nullable();
            $table->json('keywords')->nullable();
            $table->text('change_summary')->nullable();
            $table->json('meta_snapshot')->nullable();
            $table->text('search_text')->nullable();
            $table->boolean('ai_assisted')->default(false);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->boolean('self_approved')->default(false);
            $table->timestamps();
            $table->unique(['process_id', 'number']); // several NULLs allowed (unpublished)
        });

        Schema::create('clarto_process_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('clarto_positions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['process_id', 'position_id']);
        });

        Schema::create('clarto_process_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->string('type', 10);   // area | position | user
            $table->unsignedBigInteger('target_id');
            $table->string('effect', 5)->default('allow'); // allow | deny (deny: type user only)
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['process_id', 'type', 'target_id']);
        });

        Schema::create('clarto_process_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->foreignId('process_version_id')->constrained('clarto_process_versions')->cascadeOnDelete();
            $table->string('kind', 10); // image | video | file
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('original_name', 200);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('caption', 300)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_process_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->unsignedBigInteger('process_version_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 30);
            $table->text('note')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('clarto_process_user_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('process_id')->constrained('clarto_processes')->cascadeOnDelete();
            $table->foreignId('team_id');
            $table->boolean('favorite')->default(false);
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedBigInteger('last_viewed_version_id')->nullable();
            $table->json('checked_steps')->nullable();
            $table->unsignedBigInteger('read_confirmed_version_id')->nullable();
            $table->timestamp('read_confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'process_id']);
        });

        Schema::create('clarto_process_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('improvement_request_id')->unique()->constrained('clarto_improvement_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('process_id')->nullable();
            $table->unsignedBigInteger('process_version_id')->nullable();
            $table->string('step_key', 26)->nullable();
            $table->string('source', 12); // lector | asistente
            $table->unsignedBigInteger('assistant_query_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('clarto_process_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->unique();
            $table->unsignedTinyInteger('default_review_months')->default(12);
            $table->boolean('require_four_eyes')->default(true);
            $table->boolean('review_tasks_in_tarmio')->default(true);
            $table->timestamps();
        });

        Schema::create('clarto_process_map_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('key', 12);
            $table->string('title', 160);
            $table->string('area_code', 3);
            $table->string('category', 20);
            $table->string('business_line', 20);
            $table->string('kind', 10);
            $table->string('owner_position_key', 40)->nullable();
            $table->json('executing_position_keys');
            $table->text('trigger');
            $table->json('liberu_refs');
            $table->json('tarmio_kinds')->nullable();
            $table->unsignedTinyInteger('priority');
            $table->text('priority_reason')->nullable();
            $table->string('template_key', 40);
            $table->string('status', 16); // por_documentar | a_validar | descartado
            $table->unsignedBigInteger('process_id')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['clarto_process_map_entries', 'clarto_process_settings', 'clarto_process_feedback', 'clarto_process_user_states',
            'clarto_process_events', 'clarto_process_attachments', 'clarto_process_audiences', 'clarto_process_positions',
            'clarto_process_versions', 'clarto_processes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
