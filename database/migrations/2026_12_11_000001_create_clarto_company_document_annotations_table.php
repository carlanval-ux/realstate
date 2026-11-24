<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Documentación de la empresa» → expedientes: a thread of annotations per record (date, author, text, at most one
 * attachment). Each document's `notes` becomes its first annotation (author: who created the document; date: its
 * created_at) and then the column is dropped. Copy and drop run in the same migration; on SQLite (the panel's
 * database) both are in the migrator's transaction, so a failure leaves the notes where they were.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_company_document_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_document_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->text('body');
            $table->json('file')->nullable();             // {path, name, mime, size} on the private disk
            $table->timestamp('created_at')->nullable();
        });

        if (! Schema::hasColumn('clarto_company_documents', 'notes')) {
            return;
        }
        DB::table('clarto_company_documents')->whereNotNull('notes')->orderBy('id')->select(['id', 'notes', 'created_by', 'created_at'])
            ->lazyById(200)->each(function (object $document): void {
                if (trim((string) $document->notes) === '') {
                    return;
                }
                DB::table('clarto_company_document_annotations')->insert([
                    'company_document_id' => $document->id, 'user_id' => $document->created_by,
                    'body' => trim((string) $document->notes), 'created_at' => $document->created_at,
                ]);
            });
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }

    /** Back to one `notes` field: every annotation's text, oldest first. Attachments stay on disk, unreferenced. */
    public function down(): void
    {
        Schema::table('clarto_company_documents', function (Blueprint $table): void {
            $table->text('notes')->nullable();
        });
        DB::table('clarto_company_document_annotations')->orderBy('created_at')->orderBy('id')->get(['company_document_id', 'body'])
            ->groupBy('company_document_id')
            ->each(fn ($annotations, $id) => DB::table('clarto_company_documents')->where('id', $id)
                ->update(['notes' => $annotations->pluck('body')->implode("\n\n")]));
        Schema::dropIfExists('clarto_company_document_annotations');
    }
};
