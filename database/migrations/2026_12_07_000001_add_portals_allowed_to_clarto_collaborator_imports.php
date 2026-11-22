<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Puede difundirse en portales externos» en la ficha del colaborador (desactivado por defecto; requiere además la
 * fecha de autorización por escrito). Sin él, sus inmuebles no se envían a pisos.com ni a otros portales.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_collaborator_imports', function (Blueprint $table): void {
            $table->boolean('portals_allowed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('clarto_collaborator_imports', function (Blueprint $table): void {
            $table->dropColumn('portals_allowed');
        });
    }
};
