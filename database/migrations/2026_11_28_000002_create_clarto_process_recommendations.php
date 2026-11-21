<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** «Recomendaciones de documentación» (modelo-datos.md §6). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_process_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('type', 20);
            $table->string('title', 200);
            $table->text('reason');
            $table->unsignedTinyInteger('priority');
            $table->unsignedBigInteger('suggested_position_id')->nullable();
            $table->unsignedBigInteger('suggested_user_id')->nullable();
            $table->unsignedBigInteger('map_entry_id')->nullable();
            $table->unsignedBigInteger('process_id')->nullable();
            $table->string('template_key', 40)->nullable();
            $table->text('body')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->json('evidence')->nullable();
            $table->json('similar')->nullable();
            $table->string('fingerprint', 64);
            $table->string('status', 12)->index();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_process_recommendations');
    }
};
