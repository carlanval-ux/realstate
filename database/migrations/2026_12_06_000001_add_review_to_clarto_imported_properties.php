<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca blanca de los inmuebles de colaboradores: avisos de revisión de un inmueble importado (textos con rastros del
 * colaborador quitados, su visita virtual pendiente de decidir, fotos con posible marca de agua) hasta que el equipo
 * los da por revisados. Ver docs/clarto/importacion-colaboradores.md («Marca blanca»).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_imported_properties', function (Blueprint $table): void {
            $table->json('review')->nullable();   // {textos: [...], visita: url, fotos: motivo, desde: fecha}
        });
    }

    public function down(): void
    {
        Schema::table('clarto_imported_properties', function (Blueprint $table): void {
            $table->dropColumn('review');
        });
    }
};
