<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quitar la marca de agua de las fotos de un colaborador (solo si su ficha lo autoriza): el ajuste en la ficha y una
 * fila por foto con el original (disco privado), la versión limpia (disco privado hasta que el equipo la aprueba) y
 * su estado. Ver docs/clarto/importacion-colaboradores.md («Quitar marcas de agua»).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_collaborator_imports', function (Blueprint $table): void {
            $table->boolean('watermark_removal_allowed')->default(false);   // «Autoriza a retirar sus marcas de agua de las fotos»
        });

        Schema::create('clarto_photo_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('property_id')->index();
            $table->foreignId('media_document_id')->index();
            $table->foreignId('collaborator_id');
            $table->string('status', 12)->default('pendiente');   // pendiente | procesando | listo | aprobado | descartado | error
            $table->json('mask')->nullable();                      // [[x0,y0,x1,y1], …] en proporción (0–1) de la foto
            $table->string('original_path')->nullable();           // disco privado (local): la foto tal como llegó
            $table->string('cleaned_path')->nullable();            // disco privado hasta aprobarla
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_photo_cleanups');
        Schema::table('clarto_collaborator_imports', function (Blueprint $table): void {
            $table->dropColumn('watermark_removal_allowed');
        });
    }
};
