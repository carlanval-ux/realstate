<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Solicitud de mejoras»: improvement requests from the team, with private screenshots and the history of
 * status changes and replies (who and when). Attachments: [{path, name, mime, size}] on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_improvement_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('title', 160);
            $table->text('description');
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('received')->index(); // received | in_review | planned | implemented | discarded
            $table->timestamps();
        });

        Schema::create('clarto_improvement_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('improvement_request_id')->constrained('clarto_improvement_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_improvement_request_events');
        Schema::dropIfExists('clarto_improvement_requests');
    }
};
