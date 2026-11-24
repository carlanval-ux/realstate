<?php

use App\Documents\CompanyDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/** «Documentación de la empresa» (gestor documental interno): documents, their access log and the «documentacion.ver» permission. */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_company_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->string('title', 200);
            $table->string('category', 30)->index();
            $table->string('subject', 200)->nullable();   // person or entity it refers to
            $table->date('document_date');
            $table->date('expires_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('files')->nullable();            // [{path, name, mime, size}] on the private disk
            $table->timestamp('expiry_notified_at')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
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

        $permissions = config('permission.table_names.permissions', 'permissions');
        if (! DB::table($permissions)->where('name', CompanyDocument::PERMISSION)->where('guard_name', 'web')->exists()) {
            DB::table($permissions)->insert(['name' => CompanyDocument::PERMISSION, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        rescue(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_company_document_logs');
        Schema::dropIfExists('clarto_company_documents');
        DB::table(config('permission.table_names.permissions', 'permissions'))->where('name', CompanyDocument::PERMISSION)->delete();
    }
};
