<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Documentación de la empresa» (gestor documental interno): folders, documents, approval votes and the access log. */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_company_document_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('parent_id')->nullable()->index();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::create('clarto_company_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('folder_id')->index();
            $table->string('title', 200);
            $table->string('subject', 200)->nullable();   // person or entity it refers to
            $table->date('document_date');
            $table->date('expires_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('files')->nullable();            // [{path, name, mime, size}] on the private disk
            $table->timestamp('expiry_notified_at')->nullable();
            $table->string('status', 20)->nullable()->index();   // pendiente | aprobado | firmado
            $table->unsignedSmallInteger('approvals_required')->nullable();
            $table->foreignId('review_requested_by')->nullable();   // the manager of the approval
            $table->timestamp('review_requested_at')->nullable();
            $table->timestamp('threshold_notified_at')->nullable();
            $table->foreignId('status_changed_by')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_company_document_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_document_id')->index();
            $table->foreignId('user_id');
            $table->boolean('approved');
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['company_document_id', 'user_id']);
        });

        Schema::create('clarto_company_document_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_document_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('action', 20);                 // ver | descargar
            $table->string('file_name', 200);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_company_document_logs');
        Schema::dropIfExists('clarto_company_document_votes');
        Schema::dropIfExists('clarto_company_documents');
        Schema::dropIfExists('clarto_company_document_folders');
    }
};
