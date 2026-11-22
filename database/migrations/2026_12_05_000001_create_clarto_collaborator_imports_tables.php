<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importación de inmuebles de colaboradores (docs/clarto/importacion-colaboradores.md): ajustes por colaborador
 * (origen feed/web, programación, autorización por escrito, estado de la última ejecución) y el vínculo de cada
 * inmueble importado con su anuncio de origen (clave colaborador + referencia externa).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_collaborator_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('collaborator_id')->unique();
            $table->string('source', 10)->default('ninguno');      // ninguno | feed | web
            $table->string('feed_url', 1000)->nullable();
            $table->string('feed_format', 20)->nullable();          // kyero | xml | json
            $table->json('feed_mapping')->nullable();               // formato genérico: campo => ruta en cada elemento
            $table->string('web_url', 1000)->nullable();            // página de listado o sitemap
            $table->string('web_pattern', 500)->nullable();         // patrón (con *) de las fichas de inmueble
            $table->string('schedule', 10)->default('diario');      // diario | manual
            $table->date('authorized_at')->nullable();              // autorización por escrito: sin fecha no se importa nada
            $table->string('authorization_path')->nullable();       // documento en el disco privado (local)
            $table->timestamp('requested_at')->nullable();          // «Importar ahora»: el programador la recoge en ≤ 1 min
            $table->foreignId('requested_by')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 20)->nullable();          // en_curso | ok | parcial | error | sin_autorizacion
            $table->json('last_counts')->nullable();
            $table->text('last_message')->nullable();
            $table->foreignId('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_imported_properties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('collaborator_id');
            $table->foreignId('property_id')->index();
            $table->string('source', 10);                           // feed | web
            $table->string('external_ref', 191);                    // referencia del feed o huella de la URL de la ficha
            $table->string('source_url', 1000)->nullable();
            $table->string('status', 10)->default('activo');        // activo | retirado
            $table->json('data')->nullable();                       // últimos valores leídos del origen (para detectar cambios)
            $table->json('photos')->nullable();                     // URL de origen => ruta en el disco público
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['collaborator_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_imported_properties');
        Schema::dropIfExists('clarto_collaborator_imports');
    }
};
