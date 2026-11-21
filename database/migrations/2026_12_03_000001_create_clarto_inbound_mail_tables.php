<?php

use App\InboundMail\InboundMailAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** «Correo entrante»: copy of each message read from the company mailbox, staff rules, per-team settings + IMAP cursor, permissions. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clarto_inbound_mails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->index();
            $table->char('message_hash', 40);   // sha1 of the Message-ID (or of the raw message when it has none)
            $table->string('message_id', 500)->nullable();
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('uid')->nullable();
            $table->string('from_email', 320)->nullable();
            $table->string('from_name', 200)->nullable();
            $table->string('to', 1000)->nullable();
            $table->string('subject', 1000)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->boolean('is_auto')->default(false);
            $table->longText('text_body')->nullable();   // encrypted
            $table->longText('html_body')->nullable();   // sanitised, encrypted
            $table->json('attachments')->nullable();     // [{name, type, size, path|null}]
            $table->string('category', 30)->nullable();
            $table->string('area', 30)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('method', 20)->nullable();    // hilo, referencia, regla, remitente, cabeceras, ia, manual
            $table->string('suggested_category', 30)->nullable();
            $table->string('suggested_area', 30)->nullable();
            $table->string('reason', 40)->nullable();    // why it waits for review
            $table->string('status', 20)->index();       // entrada, revisar, enrutado, archivado, atendido
            $table->string('route', 40)->nullable();
            $table->foreignId('communication_id')->nullable();
            $table->foreignId('party_id')->nullable();
            $table->string('related_type')->nullable();
            $table->string('related_id', 36)->nullable();
            $table->string('ai_model', 120)->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'message_hash']);
        });

        Schema::create('clarto_inbound_mail_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id');
            $table->string('kind', 10);   // remitente | dominio
            $table->string('value', 320);
            $table->string('category', 30);
            $table->string('area', 30)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'kind', 'value']);
        });

        Schema::create('clarto_inbound_mail_settings', function (Blueprint $table): void {
            $table->foreignId('team_id')->primary();
            $table->boolean('polling')->default(false);
            $table->unsignedSmallInteger('interval_minutes')->default(5);
            $table->boolean('ai')->default(true);
            $table->json('area_owners')->nullable();
            $table->unsignedSmallInteger('retention_days')->default(730);
            $table->unsignedBigInteger('uidvalidity')->nullable();
            $table->unsignedBigInteger('last_uid')->nullable();
            $table->timestamp('last_poll_at')->nullable();
            $table->unsignedInteger('last_count')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });

        $permissions = config('permission.table_names.permissions', 'permissions');
        foreach (InboundMailAccess::PERMISSIONS as $name) {
            if (! DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->exists()) {
                DB::table($permissions)->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        rescue(fn () => app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions(), report: false);
    }

    public function down(): void
    {
        Schema::dropIfExists('clarto_inbound_mails');
        Schema::dropIfExists('clarto_inbound_mail_rules');
        Schema::dropIfExists('clarto_inbound_mail_settings');
        DB::table(config('permission.table_names.permissions', 'permissions'))->whereIn('name', InboundMailAccess::PERMISSIONS)->delete();
    }
};
