<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Incidencias y mejoras»: the improvement requests become team forum threads. Additive: a thread type (existing
 * requests are «mejora»), photos on replies ([{path, name, mime, size}] on the private disk, like the request's),
 * and Laravel's notifications table for the in-panel notices (Filament database notifications).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clarto_improvement_requests', function (Blueprint $table): void {
            $table->string('type', 20)->default('mejora')->index(); // incidencia | mejora | modificacion
        });
        Schema::table('clarto_improvement_request_events', function (Blueprint $table): void {
            $table->json('attachments')->nullable();
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clarto_improvement_request_events', fn (Blueprint $table) => $table->dropColumn('attachments'));
        Schema::table('clarto_improvement_requests', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
