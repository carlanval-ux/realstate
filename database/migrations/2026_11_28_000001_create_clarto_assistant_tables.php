<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Preguntar a CLARTO» (modelo-datos.md §6): searchable fragments of the published procedures, the SQLite FTS5 index
 * over them (kept in sync by triggers; on another driver, or without FTS5, the assistant falls back to LIKE), the
 * query log and the per-company settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_process_chunks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('process_id')->index();
            $table->unsignedBigInteger('process_version_id')->index();
            $table->string('anchor', 40);
            $table->string('heading', 200);
            $table->text('body');
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('chars');
            $table->timestamp('created_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            try {
                DB::statement("CREATE VIRTUAL TABLE clarto_process_chunks_fts USING fts5(heading, body, content='clarto_process_chunks', content_rowid='id', tokenize='unicode61 remove_diacritics 2')");
                DB::statement('CREATE TRIGGER clarto_process_chunks_ai AFTER INSERT ON clarto_process_chunks BEGIN INSERT INTO clarto_process_chunks_fts(rowid, heading, body) VALUES (new.id, new.heading, new.body); END');
                DB::statement("CREATE TRIGGER clarto_process_chunks_ad AFTER DELETE ON clarto_process_chunks BEGIN INSERT INTO clarto_process_chunks_fts(clarto_process_chunks_fts, rowid, heading, body) VALUES ('delete', old.id, old.heading, old.body); END");
            } catch (Throwable $e) {
                report($e);   // SQLite without FTS5: the assistant searches with LIKE
            }
        }

        Schema::create('clarto_assistant_queries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('question');
            $table->unsignedBigInteger('context_version_id')->nullable();
            $table->string('outcome', 20)->index();
            $table->text('answer')->nullable();
            $table->json('cited')->nullable();
            $table->json('candidates')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('chars_sent')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->tinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->unsignedBigInteger('improvement_request_id')->nullable();
            $table->timestamps();
        });

        Schema::create('clarto_assistant_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('model')->nullable();
            $table->unsignedTinyInteger('max_context_chunks')->default(6);
            $table->unsignedInteger('max_context_chars')->default(8000);
            $table->unsignedSmallInteger('log_retention_days')->default(180);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS clarto_process_chunks_ai');
            DB::statement('DROP TRIGGER IF EXISTS clarto_process_chunks_ad');
            DB::statement('DROP TABLE IF EXISTS clarto_process_chunks_fts');
        }
        Schema::dropIfExists('clarto_assistant_settings');
        Schema::dropIfExists('clarto_assistant_queries');
        Schema::dropIfExists('clarto_process_chunks');
    }
};
